<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\BackupConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Coverage for the backups:dispatch command (DispatchScheduledBackups).
 */
class DispatchScheduledBackupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_only_due_and_enabled_configs(): void
    {
        Bus::fake();
        Carbon::setTestNow('2026-06-05 12:00:00');

        // Due: enabled, never run.
        BackupConfig::create([
            'database_name' => 'due_neverrun',
            'enabled' => true,
            'interval_minutes' => 60,
            'last_run_at' => null,
        ]);

        // Due: enabled, interval elapsed.
        BackupConfig::create([
            'database_name' => 'due_elapsed',
            'enabled' => true,
            'interval_minutes' => 60,
            'last_run_at' => now()->subMinutes(120),
        ]);

        // Not due: enabled but interval not elapsed.
        BackupConfig::create([
            'database_name' => 'not_due',
            'enabled' => true,
            'interval_minutes' => 60,
            'last_run_at' => now()->subMinutes(10),
        ]);

        // Not due: disabled.
        BackupConfig::create([
            'database_name' => 'disabled',
            'enabled' => false,
            'interval_minutes' => 60,
            'last_run_at' => null,
        ]);

        // Not due: manual-only (interval 0).
        BackupConfig::create([
            'database_name' => 'manual_only',
            'enabled' => true,
            'interval_minutes' => 0,
            'last_run_at' => null,
        ]);

        $this->artisan('backups:dispatch')->assertExitCode(0);

        Bus::assertDispatchedTimes(RunBackupJob::class, 2);
        Bus::assertDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'due_neverrun' && $job->trigger === 'scheduled');
        Bus::assertDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'due_elapsed' && $job->trigger === 'scheduled');
        Bus::assertNotDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'not_due');
        Bus::assertNotDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'disabled');
        Bus::assertNotDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'manual_only');

        Carbon::setTestNow();
    }

    public function test_dispatches_nothing_when_no_configs_are_due(): void
    {
        Bus::fake();

        BackupConfig::create([
            'database_name' => 'manual_only',
            'enabled' => true,
            'interval_minutes' => 0,
            'last_run_at' => null,
        ]);

        $this->artisan('backups:dispatch')->assertExitCode(0);

        Bus::assertNotDispatched(RunBackupJob::class);
    }

    public function test_skips_config_with_a_running_backup(): void
    {
        Bus::fake();

        $this->dueConfig('busy');
        $this->dueConfig('idle');
        Backup::create([
            'database_name' => 'busy', 'filename' => 'busy.dump', 's3_path' => 'postgres-backups/busy/busy.dump',
            'status' => 'running', 'trigger' => 'manual', 'started_at' => now()->subMinutes(30),
        ]);

        $this->artisan('backups:dispatch')->assertExitCode(0);

        Bus::assertDispatchedTimes(RunBackupJob::class, 1);
        Bus::assertDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'idle');
    }

    public function test_stale_running_row_is_marked_failed_and_does_not_block_forever(): void
    {
        Bus::fake();

        $this->dueConfig('dead', 60);
        $row = Backup::create([
            'database_name' => 'dead', 'filename' => 'dead.dump', 's3_path' => 'postgres-backups/dead/dead.dump',
            'status' => 'running', 'trigger' => 'scheduled', 'started_at' => now()->subHours(3),
        ]);
        $row->created_at = now()->subHours(3);
        $row->save();

        $this->artisan('backups:dispatch')->assertExitCode(0);

        $this->assertSame('failed', $row->fresh()->status);
        // Latest row is now failed but older than interval (3h > 60m) -> dispatched.
        Bus::assertDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'dead');
    }

    public function test_failure_backoff_skips_until_interval_has_passed_since_last_failure(): void
    {
        Bus::fake();
        Carbon::setTestNow('2026-06-05 12:00:00');

        $this->dueConfig('recent_fail', 60);
        $this->failedRow('recent_fail', 20);   // failed 20 min ago, interval 60 -> back off

        $this->dueConfig('old_fail', 60);
        $this->failedRow('old_fail', 61);      // failed 61 min ago -> retry allowed

        $this->dueConfig('fail_then_ok', 60);
        $this->failedRow('fail_then_ok', 30);
        Backup::create([
            'database_name' => 'fail_then_ok', 'filename' => 'x.dump', 's3_path' => 'postgres-backups/fail_then_ok/x.dump',
            'status' => 'success', 'trigger' => 'scheduled',
        ]);                                    // latest scheduled row is success -> no backoff

        $this->artisan('backups:dispatch')->assertExitCode(0);

        Bus::assertNotDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'recent_fail');
        Bus::assertDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'old_fail');
        Bus::assertDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'fail_then_ok');

        // dispatch never writes last_run_at
        $this->assertNull(BackupConfig::where('database_name', 'recent_fail')->value('last_run_at'));

        Carbon::setTestNow();
    }

    /**
     * QA LOW: a failed manual "Run now" must not postpone the scheduled run.
     */
    public function test_failed_manual_backup_does_not_trigger_backoff(): void
    {
        Bus::fake();

        $this->dueConfig('daily', 1440);
        $manual = $this->failedRow('daily', 5);
        $manual->update(['trigger' => 'manual']);

        $this->dueConfig('sched_fail', 1440);
        $this->failedRow('sched_fail', 5);     // scheduled failure -> still backs off

        $this->artisan('backups:dispatch')->assertExitCode(0);

        Bus::assertDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'daily');
        Bus::assertNotDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->database === 'sched_fail');
    }

    private function dueConfig(string $db, int $interval = 60): BackupConfig
    {
        return BackupConfig::create([
            'database_name' => $db,
            'enabled' => true,
            'interval_minutes' => $interval,
            'last_run_at' => null,
        ]);
    }

    private function failedRow(string $db, int $minutesAgo): Backup
    {
        $row = Backup::create([
            'database_name' => $db, 'filename' => "{$db}.dump", 's3_path' => "postgres-backups/{$db}/{$db}.dump",
            'status' => 'failed', 'trigger' => 'scheduled', 'error' => 'boom',
            'started_at' => now()->subMinutes($minutesAgo),
        ]);
        $row->created_at = now()->subMinutes($minutesAgo);
        $row->save();

        return $row;
    }
}
