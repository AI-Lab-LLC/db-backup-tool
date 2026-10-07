<?php

namespace Tests\Support;

use App\Services\BackupService;
use Symfony\Component\Process\Process;

/**
 * BackupService with pg_* replaced by a tiny PHP process (pg binaries are not
 * available locally). The real makeProcess() is still invoked so tests can
 * assert PGPASSWORD travels via env only and inspect the real argv.
 */
class FakeProcessBackupService extends BackupService
{
    /** Bytes written to the -f target; null = write nothing. */
    public ?string $dumpContent = 'PGDMP-fake-dump-content';

    public string $stderr = '';

    public int $exitCode = 0;

    /**
     * Per-invocation overrides of [exitCode, stderr], keyed by
     * "{binary basename}" or "{binary basename} {first arg}" (e.g.
     * "pg_restore --list"); the more specific key wins.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    public array $results = [];

    /** @var string[] stdout for psql (listDatabases) */
    public array $databases = [];

    /** @var Process[] processes the real makeProcess() would have run */
    public array $realProcesses = [];

    protected function makeProcess(array $command): Process
    {
        $this->realProcesses[] = parent::makeProcess($command);

        $i = array_search('-f', $command, true);
        $target = $i !== false ? (string) $command[$i + 1] : '';

        $bin = basename((string) $command[0]);
        [$exit, $stderr] = $this->results[$bin . ' ' . ($command[1] ?? '')]
            ?? $this->results[$bin]
            ?? [$this->exitCode, $this->stderr];

        $stdout = $bin === 'psql' ? implode("\n", $this->databases) : '';

        $code = '[$_, $f, $c, $e, $x, $o] = $argv;'
            . ' if ($f !== "" && $c !== "__NONE__") { file_put_contents($f, $c); }'
            . ' fwrite(STDOUT, $o); fwrite(STDERR, $e); exit((int) $x);';

        return new Process([
            PHP_BINARY, '-r', $code, '--',
            $target,
            $this->dumpContent ?? '__NONE__',
            $stderr,
            (string) $exit,
            $stdout,
        ]);
    }

    /**
     * Argv (as a shell-escaped command line) of every real process, in order.
     *
     * @return string[]
     */
    public function commandLines(): array
    {
        return array_map(fn (Process $p) => $p->getCommandLine(), $this->realProcesses);
    }
}
