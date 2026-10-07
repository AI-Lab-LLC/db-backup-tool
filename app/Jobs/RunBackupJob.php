<?php

namespace App\Jobs;

use App\Models\BackupConfig;
use App\Services\BackupAlerter;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunBackupJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Wall-clock timeout for the job: pg_dump (PROCESS_TIMEOUT 3600) plus the
     * archive check and the S3 upload. Ordering invariant (guarded by tests):
     * $timeout (5400) < $uniqueFor (5600) < queue retry_after (5700) <
     * BackupService::STALE_RUNNING_MINUTES * 60 (9000).
     */
    public int $timeout = 5400;

    /**
     * Number of attempts before the job is considered failed.
     */
    public int $tries = 2;

    /**
     * Seconds the per-database unique lock may be held (> $timeout), so a worker
     * killed mid-job cannot block the database forever. The lock is released as
     * soon as the job finishes or finally fails.
     */
    public int $uniqueFor = 5600;

    public function __construct(
        public string $database,
        public string $trigger = 'manual',
    ) {
    }

    /**
     * One queued/running backup per database at a time (manual and scheduled
     * share the lock), which also prevents same-second S3 key collisions.
     */
    public function uniqueId(): string
    {
        return $this->database;
    }

    /**
     * Queue a backup unless one for this database is already queued/running.
     *
     * Note: RunRestoreJob also holds this lock (for its target database) while
     * it runs, so a backup can't be queued during a restore.
     *
     * Plain dispatch() silently drops a job whose unique lock is held, so the
     * caller can't tell. Here the lock is taken explicitly and the job is sent
     * straight to the bus (which does not re-check uniqueness); the worker
     * releases the lock when the job finishes or finally fails, as usual.
     *
     * @return bool true if queued, false if a backup for $database is in flight
     */
    public static function dispatchIfIdle(string $database, string $trigger = 'manual'): bool
    {
        $job = new static($database, $trigger);
        $lock = new UniqueLock(app(Cache::class));

        if (! $lock->acquire($job)) {
            return false;
        }

        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            $lock->release($job);

            throw $e;
        }

        return true;
    }

    /**
     * Whether a backup of $database is queued or running (its unique lock is
     * held — also held by a running restore of $database). Non-destructive
     * probe: the lock is released again immediately if it was free.
     */
    public static function isQueuedOrRunning(string $database): bool
    {
        $job = new static($database);
        $lock = new UniqueLock(app(Cache::class));

        if (! $lock->acquire($job)) {
            return true;
        }

        $lock->release($job);

        return false;
    }

    /**
     * Run the backup via BackupService.
     *
     * HARD INVARIANT: backups.last_run_at is mutated ONLY here, and only after a
     * successful backup. pruneOld() then runs with the config's retention_days.
     * BackupService::backup() never touches last_run_at.
     */
    public function handle(BackupService $service, BackupAlerter $alerter): void
    {
        // A restore of this database is running (RunRestoreJob holds the
        // shared lock): skip WITHOUT creating a backup row. No row means no
        // failure backoff and last_run_at stays put, so backups:dispatch simply
        // re-dispatches once the restore is done. Not release()d: that would
        // burn one of $tries (false failed() alert after a long restore).
        $busy = DatabaseBusyLock::for($this->database);

        if (! $busy->get()) {
            Log::warning('Backup skipped: a restore of this database is in progress', [
                'database' => $this->database,
                'trigger' => $this->trigger,
            ]);

            return;
        }

        try {
            $this->runBackup($service, $alerter);
        } finally {
            $busy->release();
        }
    }

    private function runBackup(BackupService $service, BackupAlerter $alerter): void
    {
        $backup = $service->backup($this->database, $this->trigger);

        if ($backup->status !== 'success') {
            $alerter->backupFailed($backup);

            return;
        }

        $config = BackupConfig::query()
            ->where('database_name', $this->database)
            ->first();

        if ($config !== null) {
            $config->update(['last_run_at' => now()]);

            // A prune problem must not fail (and thus retry) a backup that
            // already succeeded — that would produce a second full dump.
            try {
                $service->pruneOld($this->database, $config->retention_days);
            } catch (Throwable $e) {
                Log::warning('Backup prune after successful backup failed', [
                    'database' => $this->database,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Called when the job finally fails (exception after all tries, or killed by
     * the worker timeout). The Backup row, if any, is left 'running' and is later
     * marked failed by BackupService::markStaleRunning().
     */
    public function failed(?Throwable $e): void
    {
        app(BackupAlerter::class)->jobFailed(
            $this->database,
            $this->trigger,
            $e ?? new \RuntimeException('Job failed without an exception'),
        );
    }
}
