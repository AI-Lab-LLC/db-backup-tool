<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
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
}
