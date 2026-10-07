<?php

namespace Tests\Feature;

use App\Http\Controllers\BackupController;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Restore;
use App\Models\User;
use App\Services\BackupAlerter;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\FakeProcessBackupService;
use Tests\TestCase;

/**
 * Block B (controller side), D (index cache), E (audit), F (disable alert).
 */
class RestoreControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeProcessBackupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.pgsql.database' => 'backup_panel']);
        $this->user = User::factory()->create(['email' => 'ops@example.com']);
        $this->actingAs($this->user);

        $this->service = new FakeProcessBackupService();
        $this->service->databases = ['nextdo', 'nextdo_copy', 'backup_panel'];
        $this->app->instance(BackupService::class, $this->service);
    }

    // ---- restore -------------------------------------------------------

    public function test_restore_is_queued_not_run_in_the_request(): void
    {
        Bus::fake();
        $backup = $this->backup();

        $this->from('/backups')->post("/backups/{$backup->id}/restore", [
            'confirm' => '1', 'target_database' => 'nextdo',
        ])->assertRedirect('/backups')->assertSessionHas('status', 'Восстановление поставлено в очередь');

        $restore = Restore::sole();
        $this->assertSame(Restore::STATUS_QUEUED, $restore->status);
        $this->assertSame($backup->id, $restore->backup_id);
        $this->assertSame('nextdo', $restore->database_name);
        $this->assertSame('nextdo', $restore->target_database);
        $this->assertSame($this->user->id, $restore->user_id);

        Bus::assertDispatched(RunRestoreJob::class, fn ($j) => $j->restoreId === $restore->id && $j->targetDatabase === 'nextdo');

        // only the existence check (psql) ran — never pg_restore in the request
        foreach ($this->service->realProcesses as $p) {
            $this->assertStringNotContainsString('pg_restore', $p->getCommandLine());
        }
    }

    public function test_restore_requires_confirm(): void
    {
        Bus::fake();
        $backup = $this->backup();

        $this->post("/backups/{$backup->id}/restore", ['target_database' => 'nextdo'])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(0, Restore::count());
        Bus::assertNotDispatched(RunRestoreJob::class);
    }

    public function test_restore_into_other_database_requires_mismatch_confirmation(): void
    {
        Bus::fake();
        $backup = $this->backup();

        $this->post("/backups/{$backup->id}/restore", ['confirm' => '1', 'target_database' => 'nextdo_copy'])
            ->assertSessionHasErrors('confirm_mismatch');
        $this->assertSame(0, Restore::count());

        $this->post("/backups/{$backup->id}/restore", [
            'confirm' => '1', 'confirm_mismatch' => '1', 'target_database' => 'nextdo_copy',
        ])->assertSessionHas('status');

        $this->assertSame('nextdo_copy', Restore::sole()->target_database);
        Bus::assertDispatchedTimes(RunRestoreJob::class, 1);
    }

    public function test_restore_rejects_bad_target_name(): void
    {
        Bus::fake();
        $backup = $this->backup();

        $this->post("/backups/{$backup->id}/restore", [
            'confirm' => '1', 'confirm_mismatch' => '1', 'target_database' => 'x; drop',
        ])->assertSessionHasErrors('target_database');

        $this->assertSame(0, Restore::count());
    }

    public function test_restore_into_panel_database_is_rejected_with_reason(): void
    {
        Bus::fake();
        $backup = $this->backup();

        $this->post("/backups/{$backup->id}/restore", [
            'confirm' => '1', 'confirm_mismatch' => '1', 'target_database' => 'backup_panel',
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'служебную базу панели'));

        $this->assertSame(0, Restore::count());
        Bus::assertNotDispatched(RunRestoreJob::class);
    }

    public function test_restore_into_missing_database_is_rejected(): void
    {
        Bus::fake();
        $backup = $this->backup();

        $this->post("/backups/{$backup->id}/restore", [
            'confirm' => '1', 'confirm_mismatch' => '1', 'target_database' => 'ghost',
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'не существует'));

        $this->assertSame(0, Restore::count());
    }

    public function test_restore_of_non_success_backup_is_refused(): void
    {
        Bus::fake();
        $backup = $this->backup('failed');

        $this->post("/backups/{$backup->id}/restore", ['confirm' => '1', 'target_database' => 'nextdo'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'только успешный'));

        $this->assertSame(0, Restore::count());
    }

    public function test_restore_rejected_while_backup_of_target_is_running_or_queued(): void
    {
        Bus::fake();
        $backup = $this->backup();
        $this->backup('running');

        $this->post("/backups/{$backup->id}/restore", ['confirm' => '1', 'target_database' => 'nextdo'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'выполняется или стоит в очереди'));
        $this->assertSame(0, Restore::count());

        // queued (unique lock held, no row yet)
        $this->assertTrue(RunBackupJob::dispatchIfIdle('nextdo_copy'));
        $this->post("/backups/{$backup->id}/restore", ['confirm' => '1', 'confirm_mismatch' => '1', 'target_database' => 'nextdo_copy'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Бэкап базы «nextdo_copy»'));
        $this->assertSame(0, Restore::count());
    }

    public function test_second_restore_of_same_target_is_rejected(): void
    {
        Bus::fake();
        $backup = $this->backup();

        $this->post("/backups/{$backup->id}/restore", ['confirm' => '1', 'target_database' => 'nextdo'])->assertSessionHas('status');
        $this->post("/backups/{$backup->id}/restore", ['confirm' => '1', 'target_database' => 'nextdo'])
            ->assertSessionHas('error', 'Восстановление в базу «nextdo» уже выполняется или стоит в очереди.');

        $this->assertSame(1, Restore::count());
        Bus::assertDispatchedTimes(RunRestoreJob::class, 1);
    }

    public function test_second_restore_while_first_runs_gets_the_restore_message(): void
    {
        Bus::fake();
        $backup = $this->backup();
        // A running RunRestoreJob holds RunBackupJob's unique lock for its target.
        $guard = new \Illuminate\Bus\UniqueLock(app(\Illuminate\Contracts\Cache\Repository::class));
        $this->assertTrue($guard->acquire(new RunBackupJob('nextdo')));
        $this->activeRestore('nextdo');

        $this->post("/backups/{$backup->id}/restore", ['confirm' => '1', 'target_database' => 'nextdo'])
            ->assertSessionHas('error', 'Восстановление в базу «nextdo» уже выполняется или стоит в очереди.');

        $guard->release(new RunBackupJob('nextdo'));
    }

    public function test_restore_is_audit_logged(): void
    {
        Bus::fake();
        Log::spy();
        $backup = $this->backup();

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])
            ->post("/backups/{$backup->id}/restore", ['confirm' => '1', 'target_database' => 'nextdo']);

        Log::shouldHaveReceived('info')->with('audit: restore', Mockery::on(fn ($c) => $c['user_id'] === $this->user->id
            && $c['email'] === 'ops@example.com'
            && $c['ip'] === '10.9.8.7'
            && $c['backup_id'] === $backup->id
            && $c['target'] === 'nextdo'
            && $c['result'] === 'queued'))->once();
    }

    // ---- runNow / dispatch / destroy during a restore -----------------

    public function test_run_now_refuses_while_restore_of_database_is_active(): void
    {
        Bus::fake();
        BackupConfig::create(['database_name' => 'nextdo']);
        $this->activeRestore('nextdo');

        $this->post('/backups/run', ['database' => 'nextdo'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Идёт восстановление'));

        Bus::assertNotDispatched(RunBackupJob::class);
    }

    public function test_dispatch_command_skips_database_with_active_restore(): void
    {
        Bus::fake();
        BackupConfig::create(['database_name' => 'nextdo', 'enabled' => true, 'interval_minutes' => 60]);
        BackupConfig::create(['database_name' => 'other', 'enabled' => true, 'interval_minutes' => 60]);
        $this->activeRestore('nextdo');

        $this->artisan('backups:dispatch')
            ->expectsOutputToContain('Skip nextdo: a restore into it is queued or running.')
            ->assertExitCode(0);

        Bus::assertDispatchedTimes(RunBackupJob::class, 1);
        Bus::assertDispatched(RunBackupJob::class, fn ($j) => $j->database === 'other');
    }

    public function test_dispatch_command_unblocks_after_stale_restore_is_expired(): void
    {
        Bus::fake();
        BackupConfig::create(['database_name' => 'nextdo', 'enabled' => true, 'interval_minutes' => 60]);
        $this->activeRestore('nextdo', ['started_at' => now()->subHours(5)]);

        $this->artisan('backups:dispatch')->assertExitCode(0);

        Bus::assertDispatched(RunBackupJob::class, fn ($j) => $j->database === 'nextdo');
    }

    public function test_destroy_refuses_backup_being_restored(): void
    {
        $restore = $this->activeRestore('nextdo');

        $this->delete("/backups/{$restore->backup_id}")
            ->assertSessionHas('error', 'Нельзя удалить бэкап, из которого сейчас идёт восстановление.');

        $this->assertDatabaseHas('backups', ['id' => $restore->backup_id]);
    }

    public function test_destroy_and_run_now_are_audit_logged(): void
    {
        Bus::fake();
        Log::spy();
        BackupConfig::create(['database_name' => 'nextdo']);
        $running = $this->backup('running');

        $this->post('/backups/run', ['database' => 'nextdo']);
        $this->delete("/backups/{$running->id}");

        Log::shouldHaveReceived('info')->with('audit: run_now', Mockery::on(fn ($c) => $c['database'] === 'nextdo' && $c['email'] === 'ops@example.com' && array_key_exists('ip', $c)));
        Log::shouldHaveReceived('info')->with('audit: destroy', Mockery::on(fn ($c) => $c['backup_id'] === $running->id && $c['user_id'] === $this->user->id));
    }

    // ---- saveConfig: audit + disable alert -----------------------------

    public function test_disabling_an_enabled_config_alerts_and_audits(): void
    {
        Log::spy();
        BackupConfig::create(['database_name' => 'nextdo', 'enabled' => true, 'interval_minutes' => 60]);

        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldReceive('databaseDisabled')->once()->with('nextdo', 'ops@example.com');
        $this->app->instance(BackupAlerter::class, $alerter);

        $this->post('/backups/config', ['database_name' => 'nextdo', 'interval_minutes' => 60, 'retention_days' => 7])
            ->assertSessionHas('status');

        $this->assertFalse(BackupConfig::sole()->enabled);
        Log::shouldHaveReceived('info')->with('audit: save_config', Mockery::on(fn ($c) => $c['database'] === 'nextdo' && $c['enabled'] === false));
    }

    public function test_no_disable_alert_for_new_or_already_disabled_or_enabled_config(): void
    {
        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldNotReceive('databaseDisabled');
        $this->app->instance(BackupAlerter::class, $alerter);

        // new + disabled
        $this->post('/backups/config', ['database_name' => 'a', 'interval_minutes' => 0, 'retention_days' => 7]);
        // already disabled, stays disabled
        $this->post('/backups/config', ['database_name' => 'a', 'interval_minutes' => 0, 'retention_days' => 7]);
        // enabled stays enabled
        $this->post('/backups/config', ['database_name' => 'b', 'enabled' => '1', 'interval_minutes' => 60, 'retention_days' => 7]);
        $this->post('/backups/config', ['database_name' => 'b', 'enabled' => '1', 'interval_minutes' => 30, 'retention_days' => 7]);

        $this->assertSame(2, BackupConfig::count());
    }

    public function test_database_disabled_alert_logs_and_mails(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        config(['backup.alert_email' => 'alerts@example.com']);
        Log::spy();

        app(BackupAlerter::class)->databaseDisabled('nextdo', 'ops@example.com');

        Log::shouldHaveReceived('error')->with('[backup-panel] Backups disabled: nextdo', Mockery::any());
        \Illuminate\Support\Facades\Notification::assertSentOnDemand(
            \App\Notifications\BackupAlert::class,
            fn ($n) => in_array('Database nextdo disabled by ops@example.com.', $n->lines, true),
        );
    }

    // ---- index ---------------------------------------------------------

    public function test_index_passes_latest_restores_and_caches_database_list(): void
    {
        foreach (range(1, 12) as $i) {
            $this->activeRestore("db{$i}", ['status' => Restore::STATUS_SUCCESS]);
        }

        $response = $this->get('/backups')->assertOk();
        $restores = $response->viewData('restores');
        $this->assertCount(10, $restores);
        $this->assertSame('db12', $restores->first()->target_database);
        $this->assertTrue($restores->first()->relationLoaded('backup'));
        $this->assertSame(['nextdo', 'nextdo_copy', 'backup_panel'], $response->viewData('serverDatabases'));
        $this->assertSame(['nextdo', 'nextdo_copy'], $response->viewData('restoreTargets'), 'panel DB filtered out');

        $psqlCalls = count($this->service->realProcesses);
        $this->get('/backups')->assertOk();
        $this->assertCount($psqlCalls, $this->service->realProcesses, 'second index load must use the 60s cache');
        $this->assertTrue(Cache::has(BackupController::DATABASES_CACHE_KEY));
    }

    public function test_restore_validation_does_not_use_the_index_cache(): void
    {
        Bus::fake();
        Cache::put(BackupController::DATABASES_CACHE_KEY, ['nextdo', 'gone_db'], 60);
        $backup = $this->backup();

        $this->post("/backups/{$backup->id}/restore", ['confirm' => '1', 'confirm_mismatch' => '1', 'target_database' => 'gone_db'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'не существует'));
    }

    // ---- helpers -------------------------------------------------------

    private function backup(string $status = 'success', string $db = 'nextdo'): Backup
    {
        return Backup::create([
            'database_name' => $db, 'filename' => "{$db}_x.dump", 's3_path' => "postgres-backups/{$db}/{$db}_x_" . uniqid() . '.dump',
            'status' => $status, 'trigger' => 'manual', 'size_bytes' => 5,
            'started_at' => $status === 'running' ? now() : null,
        ]);
    }

    private function activeRestore(string $target, array $attrs = []): Restore
    {
        $backup = $this->backup('success', $target);

        return Restore::create(array_merge([
            'backup_id' => $backup->id, 'database_name' => $target, 'target_database' => $target,
            'status' => Restore::STATUS_RUNNING, 'started_at' => now(),
        ], $attrs));
    }
}
