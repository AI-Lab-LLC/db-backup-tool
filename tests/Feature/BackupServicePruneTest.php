<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

        (new BackupService())->pruneOld('nextdo', 7);

        // Old one gone from both DB and S3; fresh one untouched.
        $this->assertDatabaseMissing('backups', ['id' => $old->id]);
        $this->assertDatabaseHas('backups', ['id' => $fresh->id]);
        $disk->assertMissing($oldKey);
        $disk->assertExists($freshKey);
    }

    public function test_prune_keeps_failed_backups_even_when_old(): void
    {
        $failed = Backup::create([
            'database_name' => 'nextdo',
            'filename' => 'nextdo_failed.dump',
            's3_path' => 'postgres-backups/nextdo/nextdo_failed.dump',
            'status' => 'failed',
            'trigger' => 'manual',
        ]);
        $failed->created_at = now()->subDays(99);
        $failed->save();

        (new BackupService())->pruneOld('nextdo', 7);

        $this->assertDatabaseHas('backups', ['id' => $failed->id]);
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
