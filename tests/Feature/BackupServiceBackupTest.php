<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\FakeProcessBackupService;
use Tests\TestCase;

/**
 * backup() / restore() against a mocked S3 disk (Storage::fake() always
 * succeeds, so the false / mismatch branches need a real mock).
 */
class BackupServiceBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    /** @var FilesystemAdapter&MockInterface */
    private $disk;

    private ?string $uploaded = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/backup_panel_test_' . bin2hex(random_bytes(6));
        config(['backup.tmp_dir' => $this->tmpDir, 'backup.pg.password' => 's3cr3t-pw']);

        $this->disk = Mockery::mock(FilesystemAdapter::class);
        Storage::set(config('backup.disk', 's3'), $this->disk);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*') ?: []);
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    private function expectPut(bool $result): void
    {
        $this->disk->shouldReceive('put')->once()->andReturnUsing(function ($path, $stream) use ($result) {
            $this->uploaded = is_resource($stream) ? stream_get_contents($stream) : (string) $stream;

            return $result;
        });
    }

    private function assertTmpDirEmpty(): void
    {
        $this->assertSame([], glob($this->tmpDir . '/*') ?: [], 'temp file must be removed in finally');
    }

    public function test_successful_backup_verifies_size_and_marks_success(): void
    {
        $service = new FakeProcessBackupService();
        $len = strlen($service->dumpContent);

        $this->expectPut(true);
        $this->disk->shouldReceive('size')->once()->andReturn($len);
        $this->disk->shouldNotReceive('delete');

        $backup = $service->backup('nextdo', 'scheduled');

        $this->assertSame('success', $backup->status);
        $this->assertSame($len, $backup->fresh()->size_bytes);
        $this->assertMatchesRegularExpression('#^postgres-backups/nextdo/nextdo_\d{8}_\d{6}\.dump$#', $backup->s3_path);
        $this->assertSame($service->dumpContent, $this->uploaded);
        $this->assertTmpDirEmpty();
    }

    public function test_pgpassword_is_passed_via_env_only_and_tmp_file_is_unique_per_row(): void
    {
        $service = new FakeProcessBackupService();
        $this->expectPut(true);
        $this->disk->shouldReceive('size')->andReturn(strlen($service->dumpContent));

        $backup = $service->backup('nextdo');

        $process = $service->realProcesses[0];
        $this->assertSame('s3cr3t-pw', $process->getEnv()['PGPASSWORD'] ?? null);
        $this->assertStringNotContainsString('s3cr3t-pw', $process->getCommandLine());
        $this->assertStringContainsString("backup_{$backup->id}_nextdo_", $process->getCommandLine());
        $this->assertStringContainsString($this->tmpDir, $process->getCommandLine());
    }

    public function test_put_returning_false_marks_failed_and_cleans_partial_object(): void
    {
        $service = new FakeProcessBackupService();

        $this->expectPut(false);
        $this->disk->shouldNotReceive('size');
        $this->disk->shouldReceive('delete')->once()->andReturn(true);

        $backup = $service->backup('nextdo');

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('put() returned false', $backup->fresh()->error);
        $this->assertNotNull($backup->fresh()->finished_at);
        $this->assertTmpDirEmpty();
    }

    public function test_size_mismatch_marks_failed_and_deletes_object(): void
    {
        $service = new FakeProcessBackupService();

        $this->expectPut(true);
        $this->disk->shouldReceive('size')->once()->andReturn(3);
        $this->disk->shouldReceive('delete')->once()->andReturn(true);

        $backup = $service->backup('nextdo');

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('verification failed', $backup->error);
        $this->assertStringContainsString('remote 3 bytes', $backup->error);
        $this->assertTmpDirEmpty();
    }

    public function test_size_lookup_exception_marks_failed(): void
    {
        $service = new FakeProcessBackupService();

        $this->expectPut(true);
        $this->disk->shouldReceive('size')->once()->andThrow(new RuntimeException('HeadObject 404'));
        $this->disk->shouldReceive('delete')->once()->andReturn(false);
        $this->disk->shouldReceive('exists')->once()->andReturn(false);

        $backup = $service->backup('nextdo');

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('remote unavailable', $backup->error);
    }

    public function test_empty_dump_file_fails_before_upload(): void
    {
        $service = new FakeProcessBackupService();
        $service->dumpContent = '';
        $this->disk->shouldNotReceive('put');

        $backup = $service->backup('nextdo');

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('missing or empty', $backup->error);
        $this->assertTmpDirEmpty();
    }

    public function test_missing_dump_file_fails_before_upload(): void
    {
        $service = new FakeProcessBackupService();
        $service->dumpContent = null;
        $this->disk->shouldNotReceive('put');

        $backup = $service->backup('nextdo');

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('missing or empty', $backup->error);
    }

    public function test_pg_dump_failure_marks_failed_with_stderr(): void
    {
        $service = new FakeProcessBackupService();
        $service->exitCode = 1;
        $service->stderr = 'pg_dump: error: connection failed: FATAL: password authentication failed';
        $this->disk->shouldNotReceive('put');

        $backup = $service->backup('nextdo');

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('FATAL', $backup->error);
        $this->assertTmpDirEmpty();
    }

    public function test_same_second_key_collision_fails_without_owning_the_key(): void
    {
        $this->travelTo(now()->startOfSecond());
        $key = 'postgres-backups/nextdo/nextdo_' . now()->format('Ymd_His') . '.dump';
        $existing = Backup::create([
            'database_name' => 'nextdo', 'filename' => basename($key), 's3_path' => $key,
            'status' => 'running', 'trigger' => 'manual', 'started_at' => now(),
        ]);

        $service = new FakeProcessBackupService();
        $this->disk->shouldNotReceive('put');
        $this->disk->shouldNotReceive('delete');

        $backup = $service->backup('nextdo');

        $this->assertSame('failed', $backup->status);
        $this->assertSame('', $backup->fresh()->s3_path);
        $this->assertStringContainsString('collision', $backup->error);
        $this->assertSame([], $service->realProcesses, 'pg_dump must not run on a collision');
        $this->assertSame('running', $existing->fresh()->status);
    }

    public function test_restore_streams_from_s3_and_succeeds_without_fatal(): void
    {
        $backup = Backup::create([
            'database_name' => 'nextdo', 'filename' => 'nextdo_x.dump',
            's3_path' => 'postgres-backups/nextdo/nextdo_x.dump', 'status' => 'success', 'trigger' => 'manual',
        ]);

        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, 'PGDMP-restore');
        rewind($stream);

        $this->disk->shouldReceive('readStream')->once()->with($backup->s3_path)->andReturn($stream);
        $this->disk->shouldReceive('size')->once()->with($backup->s3_path)->andReturn(strlen('PGDMP-restore'));
        $this->disk->shouldNotReceive('get');

        $service = new FakeProcessBackupService();
        $service->stderr = 'pg_restore: warning: errors ignored on restore: 2';

        $service->restore($backup, 'nextdo_copy');

        $cmd = $service->realProcesses[0]->getCommandLine();
        $this->assertStringContainsString("'--clean' '--if-exists' '--no-owner'", $cmd);
        $this->assertStringContainsString("'-d' 'nextdo_copy'", $cmd);
        $this->assertTmpDirEmpty();
    }

    public function test_restore_throws_on_fatal(): void
    {
        $backup = Backup::create([
            'database_name' => 'nextdo', 'filename' => 'nextdo_x.dump',
            's3_path' => 'postgres-backups/nextdo/nextdo_x.dump', 'status' => 'success', 'trigger' => 'manual',
        ]);
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, 'PGDMP');
        rewind($stream);
        $this->disk->shouldReceive('readStream')->andReturn($stream);
        $this->disk->shouldReceive('size')->andReturn(5);

        $service = new FakeProcessBackupService();
        $service->stderr = 'pg_restore: error: FATAL: database "x" does not exist';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL');

        $service->restore($backup, 'nextdo_copy');
    }

    /**
     * QA MEDIUM: a truncated download must abort BEFORE the destructive
     * pg_restore --clean runs.
     */
    public function test_restore_aborts_before_pg_restore_when_download_is_truncated(): void
    {
        $backup = $this->restorableBackup(['size_bytes' => 1000]);
        $this->disk->shouldReceive('readStream')->andReturn($this->memStream('PGDMP-partial'));
        $this->disk->shouldReceive('size')->once()->andReturn(1000);

        $service = new FakeProcessBackupService();

        try {
            $service->restore($backup, 'nextdo_copy');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('incomplete (13 of 1000 bytes)', $e->getMessage());
        }

        $this->assertSame([], $service->realProcesses, 'pg_restore must not run on a truncated dump');
        $this->assertTmpDirEmpty();
    }

    public function test_restore_falls_back_to_size_bytes_when_s3_size_unavailable(): void
    {
        $this->disk->shouldReceive('size')->andThrow(new RuntimeException('HeadObject failed'));

        // size_bytes matches -> restore proceeds
        $ok = $this->restorableBackup(['size_bytes' => 5]);
        $this->disk->shouldReceive('readStream')->andReturn($this->memStream('PGDMP'), $this->memStream('PGDMP'), $this->memStream('PGDMP'));
        $service = new FakeProcessBackupService();
        $service->restore($ok, 'nextdo_copy');
        $this->assertCount(1, $service->realProcesses);

        // size_bytes mismatches -> abort before pg_restore
        $bad = $this->restorableBackup(['size_bytes' => 999]);
        $service = new FakeProcessBackupService();
        try {
            $service->restore($bad, 'nextdo_copy');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('incomplete', $e->getMessage());
        }
        $this->assertSame([], $service->realProcesses);

        // no size known at all -> refuse (cannot verify)
        $unknown = $this->restorableBackup(['size_bytes' => null]);
        $service = new FakeProcessBackupService();
        try {
            $service->restore($unknown, 'nextdo_copy');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot verify', $e->getMessage());
        }
        $this->assertSame([], $service->realProcesses);
    }

    private function restorableBackup(array $attrs = []): Backup
    {
        return Backup::create(array_merge([
            'database_name' => 'nextdo', 'filename' => 'nextdo_x.dump',
            's3_path' => 'postgres-backups/nextdo/nextdo_x.dump', 'status' => 'success', 'trigger' => 'manual',
        ], $attrs));
    }

    /** @return resource */
    private function memStream(string $content)
    {
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    public function test_restore_throws_when_stream_is_null(): void
    {
        $backup = Backup::create([
            'database_name' => 'nextdo', 'filename' => 'nextdo_x.dump',
            's3_path' => 'postgres-backups/nextdo/nextdo_x.dump', 'status' => 'success', 'trigger' => 'manual',
        ]);
        $this->disk->shouldReceive('readStream')->once()->andReturn(null);

        $service = new FakeProcessBackupService();

        try {
            $service->restore($backup, 'nextdo_copy');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Dump not found on S3', $e->getMessage());
        }

        $this->assertSame([], $service->realProcesses, 'pg_restore must not run without a dump');
        $this->assertTmpDirEmpty();
    }

    public function test_base_service_is_still_constructible(): void
    {
        $this->assertInstanceOf(BackupService::class, app(BackupService::class));
    }
}
