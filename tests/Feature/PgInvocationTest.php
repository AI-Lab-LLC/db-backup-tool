<?php

namespace Tests\Feature;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\FakeProcessBackupService;
use Tests\TestCase;

/**
 * Block A: PG_BIN_DIR prefix, -w on every connecting call, and the
 * `pg_restore --list` archive check between pg_dump and the upload.
 */
class PgInvocationTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    private $disk;

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

    public function test_bin_dir_prefixes_every_binary_and_w_is_passed(): void
    {
        config(['backup.pg.bin_dir' => '/usr/lib/postgresql/17/bin/']);
        $service = new FakeProcessBackupService();
        $service->databases = ['nextdo'];

        $this->disk->shouldReceive('put')->andReturn(true);
        $this->disk->shouldReceive('size')->andReturn(strlen($service->dumpContent));

        $this->assertSame('success', $service->backup('nextdo')->status);
        $service->listDatabases();

        $argv = array_map(fn ($p) => $this->argv($p->getCommandLine()), $service->realProcesses);

        $this->assertSame('/usr/lib/postgresql/17/bin/pg_dump', $argv[0][0]);
        $this->assertSame('/usr/lib/postgresql/17/bin/pg_restore', $argv[1][0]);
        $this->assertSame('/usr/lib/postgresql/17/bin/psql', $argv[2][0]);

        $this->assertContains('-w', $argv[0], 'pg_dump must run with --no-password');
        $this->assertContains('-w', $argv[2], 'psql must run with --no-password');

        foreach ($service->realProcesses as $p) {
            $this->assertStringNotContainsString('s3cr3t-pw', $p->getCommandLine());
            $this->assertSame('s3cr3t-pw', $p->getEnv()['PGPASSWORD'] ?? null);
        }
    }

    public function test_empty_bin_dir_uses_bare_names(): void
    {
        config(['backup.pg.bin_dir' => '']);
        $service = new FakeProcessBackupService();
        $service->listDatabases();

        $this->assertSame('psql', $this->argv($service->realProcesses[0]->getCommandLine())[0]);
    }

    public function test_pg_restore_also_gets_w_and_bin_dir(): void
    {
        config(['backup.pg.bin_dir' => '/opt/pg17/bin']);
        $backup = \App\Models\Backup::create([
            'database_name' => 'nextdo', 'filename' => 'nextdo_x.dump',
            's3_path' => 'postgres-backups/nextdo/nextdo_x.dump', 'status' => 'success', 'trigger' => 'manual',
        ]);
        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, 'PGDMP');
        rewind($stream);
        $this->disk->shouldReceive('readStream')->andReturn($stream);
        $this->disk->shouldReceive('size')->andReturn(5);

        $service = new FakeProcessBackupService();
        $service->restore($backup, 'nextdo_copy');

        $argv = $this->argv($service->realProcesses[0]->getCommandLine());
        $this->assertSame('/opt/pg17/bin/pg_restore', $argv[0]);
        $this->assertContains('-w', $argv);
        $this->assertContains('--clean', $argv);
        $this->assertContains('--if-exists', $argv);
        $this->assertContains('--no-owner', $argv);
    }

    public function test_archive_is_listed_after_dump_and_before_upload(): void
    {
        $service = new FakeProcessBackupService();
        $this->disk->shouldReceive('put')->once()->andReturn(true);
        $this->disk->shouldReceive('size')->andReturn(strlen($service->dumpContent));

        $backup = $service->backup('nextdo');

        $this->assertSame('success', $backup->status);
        $this->assertCount(2, $service->realProcesses);
        $list = $this->argv($service->realProcesses[1]->getCommandLine());
        $this->assertSame(['pg_restore', '--list'], array_slice($list, 0, 2));
        $this->assertStringContainsString("backup_{$backup->id}_nextdo_", $list[2]);
    }

    public function test_archive_validation_failure_marks_failed_without_upload(): void
    {
        $service = new FakeProcessBackupService();
        $service->results['pg_restore --list'] = [1, 'pg_restore: error: input file does not appear to be a valid archive'];
        $this->disk->shouldNotReceive('put');
        $this->disk->shouldNotReceive('delete');

        $backup = $service->backup('nextdo');

        $this->assertSame('failed', $backup->fresh()->status);
        $this->assertStringStartsWith('dump archive validation failed: ', $backup->fresh()->error);
        $this->assertStringContainsString('not appear to be a valid archive', $backup->error);
        $this->assertNotNull($backup->fresh()->finished_at);
        $this->assertSame([], glob($this->tmpDir . '/*') ?: [], 'temp file must be removed in finally');
    }

    public function test_archive_validation_fatal_on_zero_exit_still_fails(): void
    {
        $service = new FakeProcessBackupService();
        $service->results['pg_restore --list'] = [0, 'FATAL: something odd'];
        $this->disk->shouldNotReceive('put');

        $this->assertSame('failed', $service->backup('nextdo')->status);
    }

    public function test_archive_validation_reports_exit_code_when_stderr_empty(): void
    {
        $service = new FakeProcessBackupService();
        $service->results['pg_restore --list'] = [3, ''];
        $this->disk->shouldNotReceive('put');

        $backup = $service->backup('nextdo');

        $this->assertSame('dump archive validation failed: pg_restore --list exited with status 3', $backup->error);
    }

    /** @return string[] */
    private function argv(string $commandLine): array
    {
        preg_match_all("/'((?:[^']|'\\\\'')*)'/", $commandLine, $m);

        return array_map(fn ($a) => str_replace("'\\''", "'", $a), $m[1]);
    }
}
