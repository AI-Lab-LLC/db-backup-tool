<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * runNow honesty (unique-lock / running row) and destroy via BackupService.
 */
class BackupControllerActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        BackupConfig::create(['database_name' => 'nextdo']);
    }

    // ---- runNow --------------------------------------------------------

    public function test_run_now_queues_when_idle(): void
    {
        Bus::fake();

        $this->from('/backups')->post('/backups/run', ['database' => 'nextdo'])
            ->assertRedirect('/backups')
            ->assertSessionHas('status', 'Бэкап базы «nextdo» поставлен в очередь.');

        Bus::assertDispatchedTimes(RunBackupJob::class, 1);
    }

    public function test_run_now_reports_honestly_when_unique_lock_is_held(): void
    {
        Bus::fake();

        // First click queues; second click while still queued must not claim success.
        $this->post('/backups/run', ['database' => 'nextdo'])->assertSessionHas('status');
        $this->post('/backups/run', ['database' => 'nextdo'])
            ->assertSessionHas('error', 'Бэкап «nextdo» уже выполняется или стоит в очереди.')
            ->assertSessionMissing('status');

        Bus::assertDispatchedTimes(RunBackupJob::class, 1);
    }

    public function test_run_now_reports_honestly_when_a_backup_row_is_running(): void
    {
        Bus::fake();
        Backup::create([
            'database_name' => 'nextdo', 'filename' => 'x.dump', 's3_path' => 'postgres-backups/nextdo/x.dump',
            'status' => 'running', 'trigger' => 'scheduled', 'started_at' => now(),
        ]);

        $this->post('/backups/run', ['database' => 'nextdo'])
            ->assertSessionHas('error', 'Бэкап «nextdo» уже выполняется или стоит в очереди.');

        Bus::assertNotDispatched(RunBackupJob::class);
    }

    public function test_dispatch_if_idle_lock_is_released_after_sync_job_runs(): void
    {
        // Real (sync) dispatch: the worker path must release the lock we took.
        $service = Mockery::mock(\App\Services\BackupService::class);
        $service->shouldReceive('backup')->twice()->andReturn(new Backup(['status' => 'failed', 'database_name' => 'nextdo']));
        $this->app->instance(\App\Services\BackupService::class, $service);
        $this->app->instance(\App\Services\BackupAlerter::class, Mockery::spy(\App\Services\BackupAlerter::class));

        $this->assertTrue(RunBackupJob::dispatchIfIdle('nextdo'));
        $this->assertTrue(RunBackupJob::dispatchIfIdle('nextdo'));
    }

    // ---- destroy -------------------------------------------------------

    public function test_destroy_deletes_object_and_row(): void
    {
        Storage::fake(config('backup.disk', 's3'));
        $disk = Storage::disk(config('backup.disk', 's3'));
        $backup = $this->backupRow('postgres-backups/nextdo/a.dump');
        $disk->put($backup->s3_path, 'dump');

        $this->from('/backups')->delete("/backups/{$backup->id}")
            ->assertRedirect('/backups')
            ->assertSessionHas('status', 'Бэкап удалён.');

        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
        $disk->assertMissing($backup->s3_path);
    }

    public function test_destroy_keeps_row_and_flashes_error_when_s3_delete_fails(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('delete')->once()->andReturn(false);
        $mock->shouldReceive('exists')->once()->andReturn(true);
        Storage::set(config('backup.disk', 's3'), $mock);

        $backup = $this->backupRow('postgres-backups/nextdo/a.dump');

        $this->delete("/backups/{$backup->id}")
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        $this->assertDatabaseHas('backups', ['id' => $backup->id]);
    }

    public function test_destroy_never_deletes_object_referenced_by_another_row(): void
    {
        Storage::fake(config('backup.disk', 's3'));
        $disk = Storage::disk(config('backup.disk', 's3'));
        $key = 'postgres-backups/nextdo/shared.dump';
        $disk->put($key, 'live');

        $failed = $this->backupRow($key, 'failed');
        $live = $this->backupRow($key, 'success');

        $this->delete("/backups/{$failed->id}")->assertSessionHas('status');

        $this->assertDatabaseMissing('backups', ['id' => $failed->id]);
        $this->assertDatabaseHas('backups', ['id' => $live->id]);
        $disk->assertExists($key);
    }

    /**
     * QA MEDIUM: an in-flight backup cannot be deleted (would orphan its object).
     */
    public function test_destroy_refuses_running_backup(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldNotReceive('delete');
        Storage::set(config('backup.disk', 's3'), $mock);

        $running = $this->backupRow('postgres-backups/nextdo/r.dump', 'running');

        $this->delete("/backups/{$running->id}")
            ->assertSessionHas('error', 'Нельзя удалить бэкап, который ещё выполняется.')
            ->assertSessionMissing('status');

        $this->assertDatabaseHas('backups', ['id' => $running->id, 'status' => 'running']);
    }

    public function test_service_delete_backup_refuses_running_row(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldNotReceive('delete');
        Storage::set(config('backup.disk', 's3'), $mock);

        $running = $this->backupRow('postgres-backups/nextdo/r.dump', 'running');

        $this->assertFalse(app(\App\Services\BackupService::class)->deleteBackup($running));
        $this->assertDatabaseHas('backups', ['id' => $running->id]);
    }

    public function test_index_hides_delete_button_for_running_rows(): void
    {
        $this->mock(\App\Services\BackupService::class, fn ($m) => $m->shouldReceive('listDatabases')->andReturn([]));

        // Compile explicitly: a stale tracked compiled view (newer mtime than the
        // source after a git checkout) would otherwise be served as-is.
        app('blade.compiler')->compile(resource_path('views/backups/index.blade.php'));

        $running = $this->backupRow('postgres-backups/nextdo/r.dump', 'running');
        $done = $this->backupRow('postgres-backups/nextdo/d.dump', 'success');

        $html = $this->get('/backups')->assertOk()->getContent();

        $this->assertStringNotContainsString(route('backups.destroy', $running), $html);
        $this->assertStringContainsString(route('backups.destroy', $done), $html);
    }

    private function backupRow(string $key, string $status = 'success'): Backup
    {
        return Backup::create([
            'database_name' => 'nextdo', 'filename' => basename($key), 's3_path' => $key,
            'status' => $status, 'trigger' => 'manual',
        ]);
    }
}
