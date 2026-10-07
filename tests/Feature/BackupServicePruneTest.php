<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class BackupServicePruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake the configured backup disk so no real S3 calls happen.
        Storage::fake(config('backup.disk', 's3'));
    }

    public function test_prune_deletes_old_successful_backups_from_s3_and_history(): void
    {
        $disk = Storage::disk(config('backup.disk', 's3'));

        $oldKey = 'postgres-backups/nextdo/nextdo_old.dump';
        $freshKey = 'postgres-backups/nextdo/nextdo_fresh.dump';
        $disk->put($oldKey, 'dump');
        $disk->put($freshKey, 'dump');

        $old = Backup::create([
            'database_name' => 'nextdo',
            'filename' => 'nextdo_old.dump',
            's3_path' => $oldKey,
            'status' => 'success',
            'trigger' => 'scheduled',
        ]);
        $old->created_at = now()->subDays(30);
        $old->save();

        $fresh = Backup::create([
            'database_name' => 'nextdo',
            'filename' => 'nextdo_fresh.dump',
            's3_path' => $freshKey,
            'status' => 'success',
            'trigger' => 'scheduled',
        ]);
        $fresh->created_at = now()->subDays(1);
        $fresh->save();

        $this->assertSame(['deleted' => 1, 'kept' => 0], (new BackupService())->pruneOld('nextdo', 7));

        // Old one gone from both DB and S3; fresh one untouched.
        $this->assertDatabaseMissing('backups', ['id' => $old->id]);
        $this->assertDatabaseHas('backups', ['id' => $fresh->id]);
        $disk->assertMissing($oldKey);
        $disk->assertExists($freshKey);
    }

    /**
     * BEHAVIOUR CHANGE: retention now applies to every status. Previously old
     * failed rows were kept forever (test_prune_keeps_failed_backups_even_when_old).
     */
    public function test_prune_removes_old_failed_and_dead_running_rows(): void
    {
        $disk = Storage::disk(config('backup.disk', 's3'));
        $partialKey = 'postgres-backups/nextdo/nextdo_failed.dump';
        $disk->put($partialKey, 'partial');

        $failed = $this->makeBackup('failed', $partialKey, 99);
        $running = $this->makeBackup('running', 'postgres-backups/nextdo/nextdo_running.dump', 30);
        $freshFailed = $this->makeBackup('failed', 'postgres-backups/nextdo/nextdo_ff.dump', 1);

        $result = (new BackupService())->pruneOld('nextdo', 7);

        $this->assertSame(['deleted' => 2, 'kept' => 0], $result);
        $this->assertDatabaseMissing('backups', ['id' => $failed->id]);
        $this->assertDatabaseMissing('backups', ['id' => $running->id]);
        $this->assertDatabaseHas('backups', ['id' => $freshFailed->id]);
        $disk->assertMissing($partialKey);
    }

    public function test_prune_keeps_row_when_s3_delete_fails(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('delete')->once()->andReturn(false);
        $mock->shouldReceive('exists')->once()->andReturn(true);
        Storage::set(config('backup.disk', 's3'), $mock);

        Log::spy();

        $old = $this->makeBackup('success', 'postgres-backups/nextdo/nextdo_old.dump', 30);
        $this->makeBackup('success', 'postgres-backups/nextdo/nextdo_new.dump', 1); // newest success (always kept)

        $result = (new BackupService())->pruneOld('nextdo', 7);

        $this->assertSame(['deleted' => 0, 'kept' => 1], $result);
        $this->assertDatabaseHas('backups', ['id' => $old->id]);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_prune_keeps_row_when_s3_delete_throws_and_existence_unknown(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('delete')->andThrow(new \RuntimeException('network'));
        $mock->shouldReceive('exists')->andThrow(new \RuntimeException('network'));
        Storage::set(config('backup.disk', 's3'), $mock);

        $old = $this->makeBackup('success', 'postgres-backups/nextdo/nextdo_old.dump', 30);
        $this->makeBackup('success', 'postgres-backups/nextdo/nextdo_new.dump', 1); // newest success (always kept)

        $result = (new BackupService())->pruneOld('nextdo', 7);

        $this->assertSame(['deleted' => 0, 'kept' => 1], $result);
        $this->assertDatabaseHas('backups', ['id' => $old->id]);
    }

    public function test_prune_deletes_row_when_delete_false_but_object_already_missing(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldReceive('delete')->once()->andReturn(false);
        $mock->shouldReceive('exists')->once()->andReturn(false);
        Storage::set(config('backup.disk', 's3'), $mock);

        $old = $this->makeBackup('success', 'postgres-backups/nextdo/nextdo_old.dump', 30);
        $this->makeBackup('success', 'postgres-backups/nextdo/nextdo_new.dump', 1); // newest success (always kept)

        $this->assertSame(['deleted' => 1, 'kept' => 0], (new BackupService())->pruneOld('nextdo', 7));
        $this->assertDatabaseMissing('backups', ['id' => $old->id]);
    }

    public function test_prune_deletes_row_with_empty_s3_path_without_touching_s3(): void
    {
        $mock = Mockery::mock(FilesystemAdapter::class);
        $mock->shouldNotReceive('delete');
        Storage::set(config('backup.disk', 's3'), $mock);

        $old = $this->makeBackup('failed', '', 30);

        $this->assertSame(['deleted' => 1, 'kept' => 0], (new BackupService())->pruneOld('nextdo', 7));
        $this->assertDatabaseMissing('backups', ['id' => $old->id]);
    }

    public function test_prune_never_deletes_object_still_referenced_by_a_surviving_row(): void
    {
        $disk = Storage::disk(config('backup.disk', 's3'));
        $key = 'postgres-backups/nextdo/nextdo_shared.dump';
        $disk->put($key, 'live dump');

        $oldFailed = $this->makeBackup('failed', $key, 30);
        $liveSuccess = $this->makeBackup('success', $key, 1);

        (new BackupService())->pruneOld('nextdo', 7);

        $this->assertDatabaseMissing('backups', ['id' => $oldFailed->id]);
        $this->assertDatabaseHas('backups', ['id' => $liveSuccess->id]);
        $disk->assertExists($key);
    }

    public function test_mark_stale_running_only_touches_rows_older_than_two_hours(): void
    {
        $stale = $this->makeBackup('running', 'postgres-backups/nextdo/a.dump', 0);
        $stale->started_at = now()->subMinutes(BackupService::STALE_RUNNING_MINUTES + 1);
        $stale->save();

        $active = $this->makeBackup('running', 'postgres-backups/nextdo/b.dump', 0);
        $active->started_at = now()->subMinutes(70);
        $active->save();

        $done = $this->makeBackup('success', 'postgres-backups/nextdo/c.dump', 0);
        $done->started_at = now()->subDays(3);
        $done->save();

        $this->assertSame(1, (new BackupService())->markStaleRunning());

        $stale->refresh();
        $this->assertSame('failed', $stale->status);
        $this->assertSame('stale: worker died / timed out', $stale->error);
        $this->assertNotNull($stale->finished_at);
        $this->assertSame('running', $active->fresh()->status);
        $this->assertSame('success', $done->fresh()->status);
    }

    /**
     * QA HIGH: one success 8 days old, retention 7, then only failures — the
     * only restorable dump must survive both the job's and the command's prune.
     */
    public function test_prune_never_deletes_newest_success_even_when_older_than_retention(): void
    {
        $disk = Storage::disk(config('backup.disk', 's3'));
        $key = 'postgres-backups/nextdo/nextdo_last_good.dump';
        $disk->put($key, 'last good dump');

        $lastGood = $this->makeBackup('success', $key, 8);
        $oldFail = $this->makeBackup('failed', '', 7.5);
        $freshFail = $this->makeBackup('failed', '', 0);

        $result = (new BackupService())->pruneOld('nextdo', 7);

        $this->assertSame(['deleted' => 1, 'kept' => 0], $result);
        $this->assertDatabaseHas('backups', ['id' => $lastGood->id, 'status' => 'success']);
        $disk->assertExists($key);
        $this->assertDatabaseMissing('backups', ['id' => $oldFail->id]);
        $this->assertDatabaseHas('backups', ['id' => $freshFail->id]);
    }

    public function test_prune_command_keeps_last_success_for_failing_database(): void
    {
        $disk = Storage::disk(config('backup.disk', 's3'));
        \App\Models\BackupConfig::create(['database_name' => 'nextdo', 'retention_days' => 7]);
        $key = 'postgres-backups/nextdo/nextdo_last_good.dump';
        $disk->put($key, 'last good dump');

        $older = $this->makeBackup('success', 'postgres-backups/nextdo/nextdo_older.dump', 20);
        $lastGood = $this->makeBackup('success', $key, 8);
        $this->makeBackup('failed', '', 0);

        $this->artisan('backups:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('backups', ['id' => $older->id]);
        $this->assertDatabaseHas('backups', ['id' => $lastGood->id]);
        $disk->assertExists($key);
    }

    private function makeBackup(string $status, string $key, int|float $ageDays): Backup
    {
        $backup = Backup::create([
            'database_name' => 'nextdo',
            'filename' => basename($key) ?: 'x.dump',
            's3_path' => $key,
            'status' => $status,
            'trigger' => 'scheduled',
            'started_at' => now()->subMinutes((int) round($ageDays * 1440)),
        ]);
        $backup->created_at = now()->subMinutes((int) round($ageDays * 1440));
        $backup->save();

        return $backup;
    }

    public function test_prune_only_affects_named_database(): void
    {
        $other = Backup::create([
            'database_name' => 'breeze',
            'filename' => 'breeze_old.dump',
            's3_path' => 'postgres-backups/breeze/breeze_old.dump',
            'status' => 'success',
            'trigger' => 'scheduled',
        ]);
        $other->created_at = now()->subDays(30);
        $other->save();

        (new BackupService())->pruneOld('nextdo', 7);

        $this->assertDatabaseHas('backups', ['id' => $other->id]);
    }

    public function test_prune_with_non_positive_retention_deletes_nothing(): void
    {
        $old = Backup::create([
            'database_name' => 'nextdo',
            'filename' => 'nextdo_old.dump',
            's3_path' => 'postgres-backups/nextdo/nextdo_old.dump',
            'status' => 'success',
            'trigger' => 'scheduled',
        ]);
        $old->created_at = now()->subDays(365);
        $old->save();

        (new BackupService())->pruneOld('nextdo', 0);

        $this->assertDatabaseHas('backups', ['id' => $old->id]);
    }
}
