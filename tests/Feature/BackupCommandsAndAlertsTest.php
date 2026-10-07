<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\BackupConfig;
use App\Notifications\BackupAlert;
use App\Services\BackupAlerter;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BackupCommandsAndAlertsTest extends TestCase
{
    use RefreshDatabase;

    // ---- backups:prune -------------------------------------------------

    public function test_prune_command_marks_stale_then_prunes_configs_with_retention(): void
    {
        BackupConfig::create(['database_name' => 'nextdo', 'retention_days' => 7]);
        BackupConfig::create(['database_name' => 'breeze', 'retention_days' => 0]);

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('markStaleRunning')->once()->ordered()->andReturn(2);
        $service->shouldReceive('markStaleRestores')->once()->ordered()->andReturn(0);
        $service->shouldReceive('pruneOld')->once()->ordered()->with('nextdo', 7)->andReturn(['deleted' => 3, 'kept' => 1]);
        $service->shouldNotReceive('pruneOld')->with('breeze', Mockery::any());
        $this->app->instance(BackupService::class, $service);

        $this->artisan('backups:prune')
            ->expectsOutputToContain('Marked 2 stale')
            ->expectsOutputToContain('Pruned 3 backup(s); 1 kept')
            ->assertExitCode(0);
    }

    public function test_prune_command_end_to_end_on_fake_disk(): void
    {
        Storage::fake(config('backup.disk', 's3'));
        BackupConfig::create(['database_name' => 'nextdo', 'retention_days' => 7]);

        $old = Backup::create(['database_name' => 'nextdo', 'filename' => 'a.dump', 's3_path' => 'postgres-backups/nextdo/a.dump', 'status' => 'failed', 'trigger' => 'manual']);
        $old->created_at = now()->subDays(10);
        $old->save();

        $stuck = Backup::create(['database_name' => 'nextdo', 'filename' => 'b.dump', 's3_path' => 'postgres-backups/nextdo/b.dump', 'status' => 'running', 'trigger' => 'manual', 'started_at' => now()->subHours(5)]);

        $this->artisan('backups:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('backups', ['id' => $old->id]);
        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame(BackupService::STALE_RUNNING_ERROR, $stuck->fresh()->error);
    }

    // ---- alerts --------------------------------------------------------

    public function test_alert_only_logs_when_email_not_configured(): void
    {
        config(['backup.alert_email' => null]);
        Notification::fake();
        Log::spy();

        app(BackupAlerter::class)->backupFailed($this->failedBackup());

        Notification::assertNothingSent();
        Log::shouldHaveReceived('error')->once();
    }

    public function test_alert_sends_notification_when_configured(): void
    {
        config(['backup.alert_email' => 'ops@example.com']);
        Notification::fake();

        app(BackupAlerter::class)->backupFailed($this->failedBackup());

        Notification::assertSentOnDemand(BackupAlert::class, function (BackupAlert $n, array $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routes['mail'] === 'ops@example.com'
                && str_contains($n->subject, 'nextdo')
                && in_array('Error: boom', $n->lines, true);
        });
    }

    public function test_alert_send_exception_is_swallowed(): void
    {
        config(['backup.alert_email' => 'ops@example.com']);
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('smtp down'));
        Log::spy();

        app(BackupAlerter::class)->backupFailed($this->failedBackup());

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($msg, $ctx) => $msg === 'Backup alert e-mail could not be sent' && $ctx['error'] === 'smtp down'
        );
    }

    public function test_failed_job_run_sends_alert_end_to_end(): void
    {
        config(['backup.alert_email' => 'ops@example.com']);
        Notification::fake();

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('backup')->andReturn($this->failedBackup());
        $this->app->instance(BackupService::class, $service);

        \App\Jobs\RunBackupJob::dispatchSync('nextdo', 'scheduled');

        Notification::assertSentOnDemandTimes(BackupAlert::class, 1);
    }

    // ---- backups:check-stale ------------------------------------------

    public function test_check_stale_alerts_only_overdue_scheduled_enabled_configs(): void
    {
        $this->travelTo(now()->startOfMinute());

        $overdue = BackupConfig::create(['database_name' => 'overdue', 'interval_minutes' => 60]);
        $this->successRow('overdue', 121);

        BackupConfig::create(['database_name' => 'healthy', 'interval_minutes' => 60]);
        $this->successRow('healthy', 90);

        $never = BackupConfig::create(['database_name' => 'never', 'interval_minutes' => 60]);
        $never->created_at = now()->subHours(5);
        $never->save();

        BackupConfig::create(['database_name' => 'brand_new', 'interval_minutes' => 60]);
        BackupConfig::create(['database_name' => 'manual', 'interval_minutes' => 0]);
        $off = BackupConfig::create(['database_name' => 'off', 'interval_minutes' => 60, 'enabled' => false]);
        $off->created_at = now()->subDays(3);
        $off->save();

        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldReceive('staleDatabase')->once()->with('never', 60, null);
        $alerter->shouldReceive('staleDatabase')->once()->with('overdue', 60, Mockery::type('string'));
        $this->app->instance(BackupAlerter::class, $alerter);

        $this->artisan('backups:check-stale')
            ->expectsOutputToContain('2 stale database(s).')
            ->assertExitCode(0);
    }

    public function test_check_stale_alerts_once_per_episode_and_resets_after_new_success(): void
    {
        $this->travelTo(now()->startOfMinute());
        BackupConfig::create(['database_name' => 'nextdo', 'interval_minutes' => 60]);
        $this->successRow('nextdo', 200);

        $alerter = Mockery::mock(BackupAlerter::class);
        $this->app->instance(BackupAlerter::class, $alerter);

        // Episode 1: first run alerts, the next hourly runs stay quiet.
        $alerter->shouldReceive('staleDatabase')->once();
        $this->artisan('backups:check-stale')->assertExitCode(0);
        $this->travel(1)->hours();
        $this->artisan('backups:check-stale')->assertExitCode(0);
        $this->travel(5)->hours();
        $this->artisan('backups:check-stale')->assertExitCode(0);
        Mockery::getContainer()->mockery_verify();

        // A new success arrives -> healthy -> marker cleared.
        $this->successRow('nextdo', 0);
        $alerter->shouldReceive('staleDatabase')->never();
        $this->artisan('backups:check-stale')->assertExitCode(0);
        $this->assertNull(\Illuminate\Support\Facades\Cache::get(\App\Console\Commands\CheckStaleBackups::cacheKey('nextdo')));

        // Episode 2: goes stale again -> alerts again (once).
        $this->travel(3)->hours();
        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldReceive('staleDatabase')->once();
        $this->app->instance(BackupAlerter::class, $alerter);
        $this->artisan('backups:check-stale')->assertExitCode(0);
        $this->travel(1)->hours();
        $this->artisan('backups:check-stale')->assertExitCode(0);
    }

    public function test_check_stale_reminds_after_24h_while_still_stale(): void
    {
        $this->travelTo(now()->startOfMinute());
        BackupConfig::create(['database_name' => 'nextdo', 'interval_minutes' => 60]);
        $this->successRow('nextdo', 200);

        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldReceive('staleDatabase')->twice();
        $this->app->instance(BackupAlerter::class, $alerter);

        $this->artisan('backups:check-stale');
        $this->travel(23)->hours();
        $this->artisan('backups:check-stale');
        $this->travel(2)->hours();
        $this->artisan('backups:check-stale');
    }

    private function successRow(string $db, int $minutesAgo): Backup
    {
        $row = Backup::create([
            'database_name' => $db, 'filename' => 'x.dump', 's3_path' => "postgres-backups/{$db}/x.dump",
            'status' => 'success', 'trigger' => 'scheduled', 'finished_at' => now()->subMinutes($minutesAgo),
        ]);
        $row->created_at = now()->subMinutes($minutesAgo);
        $row->save();

        return $row;
    }

    private function failedBackup(): Backup
    {
        return Backup::create([
            'database_name' => 'nextdo', 'filename' => 'x.dump', 's3_path' => 'postgres-backups/nextdo/x.dump',
            'status' => 'failed', 'trigger' => 'scheduled', 'error' => 'boom', 'started_at' => now(),
        ]);
    }
}
