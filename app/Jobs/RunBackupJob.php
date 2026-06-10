<?php

namespace App\Jobs;

use App\Models\BackupConfig;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Wall-clock timeout for the job, kept above the pg_* PROCESS_TIMEOUT (3600).
     */
    public int $timeout = 3700;

    /**
     * Number of attempts before the job is considered failed.
     */
    public int $tries = 2;

    public function __construct(
        public string $database,
        public string $trigger = 'manual',
    ) {
    }

    /**
     * Run the backup via BackupService.
     *
     * HARD INVARIANT: backups.last_run_at is mutated ONLY here, and only after a
     * successful backup. pruneOld() then runs with the config's retention_days.
     * BackupService::backup() never touches last_run_at.
     */
    public function handle(BackupService $service): void
    {
        $backup = $service->backup($this->database, $this->trigger);

        if ($backup->status !== 'success') {
            return;
        }

        $config = BackupConfig::query()
            ->where('database_name', $this->database)
            ->first();

        if ($config !== null) {
            $config->update(['last_run_at' => now()]);
            $service->pruneOld($this->database, $config->retention_days);
        }
    }
}
