<?php

namespace Tests\Feature;

use App\Jobs\DatabaseBusyLock;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\Backup;
use App\Models\Restore;
use App\Services\BackupAlerter;
use App\Services\BackupService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunRestoreJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_shape(): void
    {
        $job = new RunRestoreJob(5, 'nextdo_copy');

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('nextdo_copy', $job->uniqueId());
        $this->assertSame(1, $job->tries, 'destructive restore must never auto-retry');
        $this->assertSame(5400, $job->timeout);
        $this->assertSame(5600, $job->uniqueFor);
    }

    public function test_success_marks_running_then_success(): void
    {
        $restore = $this->queuedRestore();

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('restore')->once()
            ->with(Mockery::on(fn ($b) => $b->id === $restore->backup_id), 'nextdo_copy')
            ->andReturnUsing(function () use ($restore) {
                $this->assertSame(Restore::STATUS_RUNNING, $restore->fresh()->status);
                $this->assertNotNull($restore->fresh()->started_at);
            });
        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldNotReceive('restoreFailed');

        $this->runJob($restore, $service, $alerter);

        $fresh = $restore->fresh();
        $this->assertSame(Restore::STATUS_SUCCESS, $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertNotNull($fresh->finished_at);
        $this->assertLocksFree('nextdo_copy');
    }

    public function test_failure_marks_failed_with_error_and_alerts(): void
    {
        $restore = $this->queuedRestore();

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('restore')->once()->andThrow(new RuntimeException('Restore failed (FATAL): boom'));
        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldReceive('restoreFailed')->once()->with(Mockery::on(fn ($r) => $r->id === $restore->id));

        $this->runJob($restore, $service, $alerter);

        $fresh = $restore->fresh();
        $this->assertSame(Restore::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('FATAL', $fresh->error);
        $this->assertNotNull($fresh->finished_at);
        $this->assertLocksFree('nextdo_copy');
    }

    public function test_busy_lock_held_by_a_running_backup_fails_restore_without_running_it(): void
    {
        $restore = $this->queuedRestore();
        $busy = DatabaseBusyLock::for('nextdo_copy');
        $this->assertTrue($busy->get());

        $service = Mockery::mock(BackupService::class);
        $service->shouldNotReceive('restore');
        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldReceive('restoreFailed')->once();

        $this->runJob($restore, $service, $alerter);

        $this->assertSame(Restore::STATUS_FAILED, $restore->fresh()->status);
        $this->assertSame('backup of nextdo_copy in progress, retry later', $restore->fresh()->error);
        $busy->release();
    }

    public function test_queued_backup_of_target_fails_restore(): void
    {
        $restore = $this->queuedRestore();
        // A queued RunBackupJob holds its unique lock until it finishes.
        $guard = new UniqueLock(app(Cache::class));
        $this->assertTrue($guard->acquire(new RunBackupJob('nextdo_copy')));

        $service = Mockery::mock(BackupService::class);
        $service->shouldNotReceive('restore');

        $this->runJob($restore, $service, Mockery::spy(BackupAlerter::class));

        $this->assertSame(Restore::STATUS_FAILED, $restore->fresh()->status);
        $this->assertStringContainsString('backup of nextdo_copy in progress or queued', $restore->fresh()->error);
        $guard->release(new RunBackupJob('nextdo_copy'));
    }

    public function test_running_backup_row_for_target_fails_restore(): void
    {
        $restore = $this->queuedRestore();
        Backup::create([
            'database_name' => 'nextdo_copy', 'filename' => 'r.dump', 's3_path' => 'k', 'status' => 'running',
            'trigger' => 'scheduled', 'started_at' => now(),
        ]);

        $service = Mockery::mock(BackupService::class);
        $service->shouldNotReceive('restore');

        $this->runJob($restore, $service, Mockery::spy(BackupAlerter::class));

        $this->assertSame(Restore::STATUS_FAILED, $restore->fresh()->status);
        $this->assertLocksFree('nextdo_copy');
    }

    public function test_backup_cannot_be_queued_while_restore_runs(): void
    {
        $restore = $this->queuedRestore();

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('restore')->once()->andReturnUsing(function () {
            $this->assertFalse(RunBackupJob::dispatchIfIdle('nextdo_copy'), 'backup must not queue during restore');
            $this->assertTrue(RunBackupJob::isQueuedOrRunning('nextdo_copy'));
        });

        $this->runJob($restore, $service, Mockery::spy(BackupAlerter::class));

        $this->assertSame(Restore::STATUS_SUCCESS, $restore->fresh()->status);
        $this->assertFalse(RunBackupJob::isQueuedOrRunning('nextdo_copy'));
    }

    public function test_deleted_source_backup_fails_restore(): void
    {
        $restore = $this->queuedRestore();
        Backup::query()->whereKey($restore->backup_id)->delete();

        $service = Mockery::mock(BackupService::class);
        $service->shouldNotReceive('restore');

        $this->runJob($restore->fresh(), $service, Mockery::spy(BackupAlerter::class));

        $this->assertNull($restore->fresh()->backup_id, 'FK is nullOnDelete');
        $this->assertSame(Restore::STATUS_FAILED, $restore->fresh()->status);
        $this->assertSame('source backup no longer exists', $restore->fresh()->error);
    }

    public function test_non_queued_row_is_ignored(): void
    {
        $restore = $this->queuedRestore(['status' => Restore::STATUS_FAILED]);

        $service = Mockery::mock(BackupService::class);
        $service->shouldNotReceive('restore');

        $this->runJob($restore, $service, Mockery::mock(BackupAlerter::class));

        $this->assertSame(Restore::STATUS_FAILED, $restore->fresh()->status);
    }

    public function test_failed_hook_marks_active_row_failed_and_alerts(): void
    {
        $restore = $this->queuedRestore(['status' => Restore::STATUS_RUNNING, 'started_at' => now()]);

        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldReceive('restoreFailed')->once();
        $this->app->instance(BackupAlerter::class, $alerter);

        (new RunRestoreJob($restore->id, 'nextdo_copy'))->failed(new RuntimeException('timed out'));

        $this->assertSame(Restore::STATUS_FAILED, $restore->fresh()->status);
        $this->assertStringContainsString('timed out', $restore->fresh()->error);
    }

    public function test_dispatch_if_idle_refuses_second_restore_of_same_target(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $this->assertTrue(RunRestoreJob::dispatchIfIdle($this->queuedRestore()));
        $this->assertFalse(RunRestoreJob::dispatchIfIdle($this->queuedRestore()));
        $this->assertTrue(RunRestoreJob::dispatchIfIdle($this->queuedRestore(['target_database' => 'other'])));
    }

    public function test_stale_restores_are_marked_failed(): void
    {
        $stale = $this->queuedRestore(['status' => Restore::STATUS_RUNNING, 'started_at' => now()->subMinutes(BackupService::STALE_RUNNING_MINUTES + 5)]);
        $lostQueued = $this->queuedRestore(['target_database' => 'b']);
        $lostQueued->created_at = now()->subMinutes(BackupService::STALE_RUNNING_MINUTES + 5);
        $lostQueued->save();
        $fresh = $this->queuedRestore(['target_database' => 'c', 'status' => Restore::STATUS_RUNNING, 'started_at' => now()->subMinutes(10)]);

        $this->assertSame(2, app(BackupService::class)->markStaleRestores());

        $this->assertSame(Restore::STATUS_FAILED, $stale->fresh()->status);
        $this->assertSame(BackupService::STALE_RUNNING_ERROR, $stale->fresh()->error);
        $this->assertSame(Restore::STATUS_FAILED, $lostQueued->fresh()->status);
        $this->assertSame(Restore::STATUS_RUNNING, $fresh->fresh()->status);
    }

    public function test_prune_keeps_backup_used_by_an_active_restore(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('backup.disk', 's3'));
        $restore = $this->queuedRestore();
        $source = Backup::find($restore->backup_id);
        $source->created_at = now()->subDays(30);
        $source->save();
        // newer success so $source is not the protected "newest success"
        Backup::create(['database_name' => 'nextdo', 'filename' => 'n.dump', 's3_path' => 'postgres-backups/nextdo/n.dump', 'status' => 'success', 'trigger' => 'manual']);

        $result = app(BackupService::class)->pruneOld('nextdo', 7);

        $this->assertSame(0, $result['deleted']);
        $this->assertNotNull($source->fresh());

        $restore->update(['status' => Restore::STATUS_SUCCESS]);
        $this->assertSame(1, app(BackupService::class)->pruneOld('nextdo', 7)['deleted']);
        $this->assertNull($source->fresh());
        $this->assertNull($restore->fresh()->backup_id);
    }

    private function runJob(Restore $restore, $service, $alerter): void
    {
        (new RunRestoreJob($restore->id, $restore->target_database))->handle($service, $alerter, app(Cache::class));
    }

    private function assertLocksFree(string $db): void
    {
        $busy = DatabaseBusyLock::for($db);
        $this->assertTrue($busy->get(), 'busy lock must be released');
        $busy->release();
        $this->assertFalse(RunBackupJob::isQueuedOrRunning($db), 'backup guard lock must be released');
    }

    private function queuedRestore(array $attrs = []): Restore
    {
        $backup = Backup::create([
            'database_name' => 'nextdo', 'filename' => 'nextdo_x.dump', 's3_path' => 'postgres-backups/nextdo/nextdo_x.dump',
            'status' => 'success', 'trigger' => 'manual', 'size_bytes' => 5,
        ]);

        return Restore::create(array_merge([
            'backup_id' => $backup->id,
            'database_name' => 'nextdo',
            'target_database' => 'nextdo_copy',
            'status' => Restore::STATUS_QUEUED,
        ], $attrs));
    }
}
