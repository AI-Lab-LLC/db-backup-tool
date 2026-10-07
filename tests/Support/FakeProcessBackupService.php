<?php

namespace Tests\Support;

use App\Services\BackupService;
use Symfony\Component\Process\Process;

/**
 * BackupService with pg_* replaced by a tiny PHP process (pg binaries are not
 * available locally). The real makeProcess() is still invoked so tests can
 * assert PGPASSWORD travels via env only.
 */
class FakeProcessBackupService extends BackupService
{
    /** Bytes written to the -f target; null = write nothing. */
    public ?string $dumpContent = 'PGDMP-fake-dump-content';

    public string $stderr = '';

    public int $exitCode = 0;

    /** @var Process[] processes the real makeProcess() would have run */
    public array $realProcesses = [];

    protected function makeProcess(array $command): Process
    {
        $this->realProcesses[] = parent::makeProcess($command);

        $i = array_search('-f', $command, true);
        $target = $i !== false ? (string) $command[$i + 1] : '';

        $code = '[$_, $f, $c, $e, $x] = $argv;'
            . ' if ($f !== "" && $c !== "__NONE__") { file_put_contents($f, $c); }'
            . ' fwrite(STDERR, $e); exit((int) $x);';

        return new Process([
            PHP_BINARY, '-r', $code, '--',
            $target,
            $this->dumpContent ?? '__NONE__',
            $this->stderr,
            (string) $this->exitCode,
        ]);
    }
}
