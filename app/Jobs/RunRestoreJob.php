<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\Restore;
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
use Throwable;

/**
 * Runs one Restore row through BackupService::restore() in the queue.
 *
 * DESTRUCTIVE (pg_restore --clean): never retried ($tries = 1). The job never
 * runs processes itself — BackupService does — and never touches backups or
 * last_run_at; it only updates its own Restore row.
 *
 * Mutual exclusion with backups of the target database:
 *  - RunBackupJob's unique lock for the target is taken for the whole restore:
 *    if it is held, a backup is queued or running -> the restore fails fast;
 *    while we hold it, no backup of the target can be queued.
 *  - DatabaseBusyLock (shared with RunBackupJob::handle) as a second guard.
 *  - A 'running' Backup row for the target also rejects the restore.
 */
class RunRestoreJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Same budget as RunBackupJob (download + pg_restore PROCESS_TIMEOUT 3600). */
    public int $timeout = 5400;

    /** A destructive restore must never be re-run automatically. */
    public int $tries = 1;

    /** Mark failed (and call failed()) right away when the timeout hits. */
    public bool $failOnTimeout = true;

    /** > $timeout, < queue retry_after. */
    public int $uniqueFor = 5600;

    public function __construct(
        public int $restoreId,
        public string $targetDatabase,
    ) {
    }

    /**
     * One queued/running restore per target database.
     */
    public function uniqueId(): string
    {
        return $this->targetDatabase;
    }

    /**
     * Queue the restore unless one into the same target is already queued or
     * running (see RunBackupJob::dispatchIfIdle for why not plain dispatch()).
     */
    public static function dispatchIfIdle(Restore $restore): bool
    {
        $job = new static($restore->id, $restore->target_database);
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

    public function handle(BackupService $service, BackupAlerter $alerter, Cache $cache): void
    {
        $restore = Restore::query()->find($this->restoreId);

        if ($restore === null || $restore->status !== Restore::STATUS_QUEUED) {
            return; // deleted, or already handled / marked stale
        }

        $target = $restore->target_database;
        $backup = $restore->backup;

        if ($backup === null) {
            $this->markFailed($restore, $alerter, 'source backup no longer exists');

            return;
        }

        if ($backup->status !== 'success') {
            $this->markFailed($restore, $alerter, "source backup #{$backup->id} is not a successful backup (status: {$backup->status})");

            return;
        }

        $backupGuard = new UniqueLock($cache);
        $guardJob = new RunBackupJob($target);

        if (! $backupGuard->acquire($guardJob)) {
            $this->markFailed($restore, $alerter, "backup of {$target} in progress or queued, retry later");

            return;
        }

        try {
            $busy = DatabaseBusyLock::for($target);

            if (! $busy->get()) {
                $this->markFailed($restore, $alerter, "backup of {$target} in progress, retry later");

                return;
            }

            try {
                $backupRunning = Backup::query()
                    ->where('database_name', $target)
                    ->where('status', 'running')
                    ->exists();

                if ($backupRunning) {
                    $this->markFailed($restore, $alerter, "backup of {$target} in progress, retry later");

                    return;
                }

                $restore->update([
                    'status' => Restore::STATUS_RUNNING,
                    'started_at' => now(),
                ]);

                try {
                    $service->restore($backup, $target);
                } catch (Throwable $e) {
                    $this->markFailed($restore, $alerter, $e->getMessage());

                    return;
                }

                $restore->update([
                    'status' => Restore::STATUS_SUCCESS,
                    'error' => null,
                    'finished_at' => now(),
                ]);
            } finally {
                $busy->release();
            }
        } finally {
            $backupGuard->release($guardJob);
        }
    }

    /**
     * Job finally failed outside handle()'s own error handling (worker timeout /
     * killed). Mark the row failed if it is still active, then alert.
     */
    public function failed(?Throwable $e): void
    {
        $restore = Restore::query()->find($this->restoreId);

        if ($restore === null || ! in_array($restore->status, Restore::ACTIVE_STATUSES, true)) {
            return;
        }

        $this->markFailed(
            $restore,
            app(BackupAlerter::class),
            'restore job failed: ' . ($e?->getMessage() ?: 'killed / timed out'),
        );
    }

    private function markFailed(Restore $restore, BackupAlerter $alerter, string $error): void
    {
        $restore->update([
            'status' => Restore::STATUS_FAILED,
            'error' => $error,
            'finished_at' => now(),
        ]);

        $alerter->restoreFailed($restore);
    }
}
