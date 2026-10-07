<?php

namespace Tests\Feature;

use App\Jobs\DatabaseBusyLock;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Services\BackupAlerter;
use App\Services\BackupService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunBackupJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_is_unique_per_database_for_longer_than_its_timeout(): void
    {
        $job = new RunBackupJob('nextdo', 'scheduled');

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('nextdo', $job->uniqueId());
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }

    public function test_database_queue_retry_after_exceeds_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        foreach ([new RunBackupJob('x'), new RunRestoreJob(1, 'x')] as $job) {
            $this->assertGreaterThan($job->timeout, $retryAfter, $job::class . ' timeout');
            $this->assertGreaterThan($job->uniqueFor, $retryAfter, $job::class . ' uniqueFor');
            $this->assertGreaterThan($job->timeout, $job->uniqueFor, $job::class . ' uniqueFor > timeout');
            $this->assertGreaterThan($job->timeout, DatabaseBusyLock::TTL_SECONDS, $job::class . ' busy-lock TTL');
        }
    }

    public function test_stale_running_threshold_exceeds_every_job_timeout(): void
    {
        foreach ([new RunBackupJob('x'), new RunRestoreJob(1, 'x')] as $job) {
            $this->assertGreaterThan($job->timeout, BackupService::STALE_RUNNING_MINUTES * 60, $job::class);
        }
    }

    public function test_job_skips_without_a_row_while_database_busy_lock_is_held(): void
    {
        $config = BackupConfig::create(['database_name' => 'nextdo', 'retention_days' => 5]);
        $lock = DatabaseBusyLock::for('nextdo');
        $this->assertTrue($lock->get());

        $service = Mockery::mock(BackupService::class);
        $service->shouldNotReceive('backup');
        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldNotReceive('backupFailed');

        (new RunBackupJob('nextdo', 'scheduled'))->handle($service, $alerter);

        $this->assertSame(0, Backup::count());
        $this->assertNull($config->fresh()->last_run_at);

        $lock->release();
    }

    public function test_job_releases_database_busy_lock_after_run(): void
    {
        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('backup')->andReturn(new Backup(['status' => 'failed', 'database_name' => 'nextdo']));

        (new RunBackupJob('nextdo'))->handle($service, Mockery::spy(BackupAlerter::class));

        $lock = DatabaseBusyLock::for('nextdo');
        $this->assertTrue($lock->get(), 'busy lock must be released in finally');
        $lock->release();
    }

    public function test_second_dispatch_for_same_database_is_dropped(): void
    {
        Bus::fake();

        RunBackupJob::dispatch('nextdo', 'scheduled');
        RunBackupJob::dispatch('nextdo', 'manual');
        RunBackupJob::dispatch('breeze', 'manual');

        Bus::assertDispatchedTimes(RunBackupJob::class, 2);
        Bus::assertDispatched(RunBackupJob::class, fn ($j) => $j->database === 'nextdo' && $j->trigger === 'scheduled');
        Bus::assertDispatched(RunBackupJob::class, fn ($j) => $j->database === 'breeze');
    }

    public function test_success_updates_last_run_at_then_prunes(): void
    {
        $config = BackupConfig::create(['database_name' => 'nextdo', 'retention_days' => 5, 'last_run_at' => null]);

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('backup')->once()->with('nextdo', 'scheduled')->andReturn(new Backup(['status' => 'success']));
        $service->shouldReceive('pruneOld')->once()->with('nextdo', 5)->andReturn(['deleted' => 0, 'kept' => 0]);

        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldNotReceive('backupFailed');

        (new RunBackupJob('nextdo', 'scheduled'))->handle($service, $alerter);

        $this->assertNotNull($config->fresh()->last_run_at);
    }

    public function test_prune_exception_does_not_fail_a_successful_backup(): void
    {
        $config = BackupConfig::create(['database_name' => 'nextdo', 'retention_days' => 5]);

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('backup')->andReturn(new Backup(['status' => 'success']));
        $service->shouldReceive('pruneOld')->andThrow(new RuntimeException('s3 down'));

        (new RunBackupJob('nextdo'))->handle($service, Mockery::mock(BackupAlerter::class));

        $this->assertNotNull($config->fresh()->last_run_at);
    }

    public function test_failure_alerts_and_does_not_touch_last_run_at_or_prune(): void
    {
        $config = BackupConfig::create(['database_name' => 'nextdo', 'retention_days' => 5]);
        $failed = new Backup(['status' => 'failed', 'error' => 'boom', 'database_name' => 'nextdo']);

        $service = Mockery::mock(BackupService::class);
        $service->shouldReceive('backup')->andReturn($failed);
        $service->shouldNotReceive('pruneOld');

        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldReceive('backupFailed')->once()->with($failed);

        (new RunBackupJob('nextdo'))->handle($service, $alerter);

        $this->assertNull($config->fresh()->last_run_at);
    }

    public function test_failed_hook_alerts(): void
    {
        $alerter = Mockery::mock(BackupAlerter::class);
        $alerter->shouldReceive('jobFailed')->once()->with('nextdo', 'manual', Mockery::type(RuntimeException::class));
        $this->app->instance(BackupAlerter::class, $alerter);

        (new RunBackupJob('nextdo'))->failed(new RuntimeException('timeout'));
    }
}
