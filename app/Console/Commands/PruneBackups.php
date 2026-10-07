<?php

namespace App\Console\Commands;

use App\Models\BackupConfig;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class PruneBackups extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backups:prune';

    /**
     * @var string
     */
    protected $description = 'Mark stale running backups/restores failed, then apply retention to every configured database';

    /**
     * Only calls BackupService — never touches processes, S3 or last_run_at.
     */
    public function handle(BackupService $service): int
    {
        $stale = $service->markStaleRunning();
        $this->info("Marked {$stale} stale running backup(s) as failed.");

        $staleRestores = $service->markStaleRestores();
        $this->info("Marked {$staleRestores} stale queued/running restore(s) as failed.");

        $deleted = 0;
        $kept = 0;
        $errors = 0;

        BackupConfig::query()
            ->where('retention_days', '>', 0)
            ->orderBy('database_name')
            ->get()
            ->each(function (BackupConfig $config) use ($service, &$deleted, &$kept, &$errors) {
                try {
                    $result = $service->pruneOld($config->database_name, $config->retention_days);
                } catch (Throwable $e) {
                    $errors++;
                    $this->error("{$config->database_name}: prune failed: {$e->getMessage()}");

                    return;
                }

                $deleted += $result['deleted'];
                $kept += $result['kept'];
                $this->line("{$config->database_name}: deleted {$result['deleted']}, kept {$result['kept']} (S3 delete failed)");
            });

        $this->info("Pruned {$deleted} backup(s); {$kept} kept due to S3 delete failures.");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
