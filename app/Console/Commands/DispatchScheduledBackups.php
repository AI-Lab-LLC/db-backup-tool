<?php

namespace App\Console\Commands;

use App\Jobs\RunBackupJob;
use App\Models\BackupConfig;
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
     * pg_* process directly. RunBackupJob owns the actual work.
     */
    public function handle(): int
    {
        $dispatched = 0;

        BackupConfig::query()
            ->where('enabled', true)
            ->get()
            ->each(function (BackupConfig $config) use (&$dispatched) {
                if (! $config->dueForBackup()) {
                    return;
                }

                RunBackupJob::dispatch($config->database_name, 'scheduled');
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} scheduled backup job(s).");

        return self::SUCCESS;
    }
}
