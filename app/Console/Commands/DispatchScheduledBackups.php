<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Services\BackupService;
use Illuminate\Console\Command;

class DispatchScheduledBackups extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backups:dispatch';

    /**
     * @var string
     */
    protected $description = 'Dispatch RunBackupJob for every enabled config whose interval is due';

    /**
     * Walk enabled configs and queue a scheduled backup for each due one.
     *
     * HARD INVARIANT: this command ONLY dispatches jobs — it never runs any
     * pg_* process directly and never writes last_run_at. RunBackupJob owns
     * the actual work.
     *
     * On top of dueForBackup() (unchanged), a due config is skipped when:
     *  - it already has a 'running' Backup row (one in flight), or
     *  - its latest SCHEDULED Backup row is 'failed' and less than interval_minutes old
     *    (failure backoff — a failed run does not move last_run_at, so without
     *    this a broken database would be re-dumped every minute).
     * Dead 'running' rows are first marked failed via the service so they can
     * never block a database indefinitely.
     */
    public function handle(BackupService $service): int
    {
        $service->markStaleRunning();

        $dispatched = 0;

        BackupConfig::query()
            ->where('enabled', true)
            ->get()
            ->each(function (BackupConfig $config) use (&$dispatched) {
                if (! $config->dueForBackup()) {
                    return;
                }

                $running = Backup::query()
                    ->where('database_name', $config->database_name)
                    ->where('status', 'running')
                    ->exists();

                if ($running) {
                    $this->line("Skip {$config->database_name}: a backup is already running.");

                    return;
                }

                // Backoff looks at scheduled runs only: a failed manual "Run now"
                // must not postpone the next scheduled backup by an interval.
                $latest = Backup::query()
                    ->where('database_name', $config->database_name)
                    ->where('trigger', 'scheduled')
                    ->latest('id')
                    ->first();

                if ($latest !== null
                    && $latest->status === 'failed'
                    && $latest->created_at !== null
                    && $latest->created_at->copy()->addMinutes($config->interval_minutes)->greaterThan(now())) {
                    $this->line("Skip {$config->database_name}: last backup failed, backing off until "
                        . $latest->created_at->copy()->addMinutes($config->interval_minutes)->toDateTimeString() . '.');

                    return;
                }

                if (! RunBackupJob::dispatchIfIdle($config->database_name, 'scheduled')) {
                    $this->line("Skip {$config->database_name}: a backup is already queued.");

                    return;
                }

                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} scheduled backup job(s).");

        return self::SUCCESS;
    }
}
