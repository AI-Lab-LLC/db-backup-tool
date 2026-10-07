<?php

namespace Tests\Feature;

use App\Models\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakeProcessBackupService;
use Tests\TestCase;

class RestoreTargetTest extends TestCase
{
    use RefreshDatabase;

    private FakeProcessBackupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.pgsql.database' => 'backup_panel']);
        $this->service = new FakeProcessBackupService();
        $this->service->databases = ['nextdo', 'nextdo_copy', 'backup_panel', 'postgres'];
    }

    public function test_existing_ordinary_database_is_allowed(): void
    {
        $this->assertNull($this->service->restoreTargetError('nextdo_copy'));
        $this->assertTrue($this->service->isRestoreTargetAllowed('nextdo'));
    }

    #[DataProvider('deniedTargets')]
    public function test_denied_targets(string $target, string $reasonFragment): void
    {
        $error = $this->service->restoreTargetError($target);

        $this->assertNotNull($error);
        $this->assertStringContainsString($reasonFragment, $error);
        $this->assertFalse($this->service->isRestoreTargetAllowed($target));
    }

    public static function deniedTargets(): array
    {
        return [
            'panel metadata db' => ['backup_panel', 'служебную базу панели'],
            'panel db other case' => ['BACKUP_PANEL', 'служебную базу панели'],
            'postgres' => ['postgres', 'системную'],
            'template0' => ['template0', 'системную'],
            'template1' => ['template1', 'системную'],
            'bad chars' => ['nextdo; drop', 'недопустимое имя'],
            'dash' => ['next-do', 'недопустимое имя'],
            'empty' => ['', 'недопустимое имя'],
            'missing on server' => ['ghost_db', 'не существует'],
        ];
    }

    public function test_static_denials_do_not_hit_the_server(): void
    {
        $this->service->restoreTargetError('postgres');
        $this->service->restoreTargetError('backup_panel');

        $this->assertSame([], $this->service->realProcesses);
    }

    public function test_server_unreachable_is_reported_as_not_allowed(): void
    {
        $this->service->results['psql'] = [2, 'psql: error: connection refused'];

        $error = $this->service->restoreTargetError('nextdo_copy');

        $this->assertStringContainsString('не удалось проверить', $error);
        $this->assertStringContainsString('connection refused', $error);
    }

    public function test_restore_itself_refuses_the_panel_database_before_any_process(): void
    {
        $backup = Backup::create([
            'database_name' => 'nextdo', 'filename' => 'x.dump', 's3_path' => 'postgres-backups/nextdo/x.dump',
            'status' => 'success', 'trigger' => 'manual', 'size_bytes' => 5,
        ]);

        try {
            $this->service->restore($backup, 'backup_panel');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('служебную базу панели', $e->getMessage());
        }

        $this->assertSame([], $this->service->realProcesses);
    }
}
