<?php

namespace App\Services;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupService
{
    /**
     * Max wall-clock time (seconds) for any pg_* / psql process.
     */
    private const PROCESS_TIMEOUT = 3600;

    /**
     * Discover every connectable, non-template database on the PostgreSQL server.
     *
     * Runs `psql ... -t -A -c "SELECT datname ..."` over the network. PGPASSWORD
     * is supplied through the process environment only — never in argv.
     *
     * @return string[] sorted list of database names
     */
    public function listDatabases(): array
    {
        $sql = 'SELECT datname FROM pg_database '
            . 'WHERE datistemplate = false AND datallowconn = true '
            . 'ORDER BY datname';

        $process = $this->makeProcess([
            'psql',
            ...$this->connectionArgs(),
            '-d', 'postgres',
            '-t',  // tuples only (no header / footer)
            '-A',  // unaligned output
            '-c', $sql,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Failed to list databases: ' . trim($process->getErrorOutput())
            );
        }

        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
    }

    /**
     * Dump a database (custom format) and upload it to S3.
     *
     * Creates a Backup history row (status=running), runs `pg_dump -Fc` into a
     * temp file under storage/app/tmp, uploads it to
     * postgres-backups/{db}/{db}_{timestamp}.dump, then marks the row success.
     *
     * On a non-zero exit or FATAL stderr the row is marked failed. The temp file
     * is always removed in a finally block.
     *
     * NOTE: this method intentionally does NOT touch BackupConfig::last_run_at and
     * does NOT call pruneOld() — that is RunBackupJob's responsibility (layer
     * decoupling invariant).
     */
    public function backup(string $database, string $trigger = 'manual'): Backup
    {
        $timestamp = now()->format('Ymd_His');
        $filename = "{$database}_{$timestamp}.dump";
        $s3Path = $this->s3Key($database, $filename);

        $backup = Backup::create([
            'database_name' => $database,
            'filename' => $filename,
            's3_path' => $s3Path,
            'status' => 'running',
            'trigger' => $trigger,
            'started_at' => now(),
        ]);

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $filename;

        try {
            $process = $this->makeProcess([
                'pg_dump',
                '-Fc',                 // custom (compressed) archive format
                ...$this->connectionArgs(),
                '-d', $database,
                '-f', $tmpFile,
            ]);

            $process->run();

            $stderr = trim($process->getErrorOutput());

            if (! $process->isSuccessful() || $this->hasFatal($stderr)) {
                $message = $stderr !== '' ? $stderr : 'pg_dump exited with a non-zero status';

                $backup->update([
                    'status' => 'failed',
                    'error' => $message,
                    'finished_at' => now(),
                ]);

                return $backup;
            }

            // Stream the dump to S3 (avoids loading the whole file into memory).
            $stream = fopen($tmpFile, 'rb');

            try {
                $this->disk()->put($s3Path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $backup->update([
                'size_bytes' => @filesize($tmpFile) ?: null,
                's3_path' => $s3Path,
                'filename' => $filename,
                'status' => 'success',
                'finished_at' => now(),
            ]);

            return $backup;
        } catch (\Throwable $e) {
            $backup->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return $backup;
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Generate a short-lived presigned URL to download a backup from S3.
     */
    public function downloadUrl(Backup $backup): string
    {
        return $this->disk()->temporaryUrl(
            $backup->s3_path,
            now()->addMinutes((int) config('backup.download_url_ttl', 15))
        );
    }

    /**
     * Restore a backup dump into the target database.
     *
     * DESTRUCTIVE: uses `pg_restore --clean --if-exists --no-owner`. The dump is
     * pulled from S3 into a temp file and restored over the network. pg_restore
     * routinely writes warnings to stderr, so this is treated as failed ONLY when
     * stderr contains the substring "FATAL". The temp file is always removed in a
     * finally block.
     *
     * @throws RuntimeException on an invalid target name or a FATAL restore error
     */
    public function restore(Backup $backup, string $targetDatabase): void
    {
        if (! $this->isValidDatabaseName($targetDatabase)) {
            throw new RuntimeException("Invalid target database name: {$targetDatabase}");
        }

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . 'restore_' . $backup->id . '_' . basename($backup->filename);

        try {
            // Pull the dump down from S3.
            $contents = $this->disk()->get($backup->s3_path);
            if ($contents === null) {
                throw new RuntimeException("Dump not found on S3: {$backup->s3_path}");
            }
            file_put_contents($tmpFile, $contents);

            $process = $this->makeProcess([
                'pg_restore',
                '--clean',
                '--if-exists',
                '--no-owner',
                ...$this->connectionArgs(),
                '-d', $targetDatabase,
                $tmpFile,
            ]);

            $process->run();

            $stderr = trim($process->getErrorOutput());

            // Warnings on stderr are normal for pg_restore; only FATAL means failure.
            if ($this->hasFatal($stderr)) {
                throw new RuntimeException("Restore failed (FATAL): {$stderr}");
            }
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Delete successful backups for a database older than the retention window,
     * removing both the S3 object and the history row.
     *
     * A non-positive retention means "keep everything" — nothing is deleted.
     */
    public function pruneOld(string $database, int $retentionDays): void
    {
        if ($retentionDays <= 0) {
            return;
        }

        $cutoff = now()->subDays($retentionDays);

        Backup::query()
            ->where('database_name', $database)
            ->where('status', 'success')
            ->where('created_at', '<', $cutoff)
            ->get()
            ->each(function (Backup $backup) {
                if ($backup->s3_path) {
                    $this->disk()->delete($backup->s3_path);
                }
                $backup->delete();
            });
    }

    /**
     * Shared connection arguments for every pg_* / psql invocation.
     *
     * The password is deliberately absent here — it travels only through the
     * process environment (see makeProcess()).
     *
     * @return string[]
     */
    private function connectionArgs(): array
    {
        return [
            '-h', (string) config('backup.pg.host'),
            '-p', (string) config('backup.pg.port'),
            '-U', (string) config('backup.pg.user'),
        ];
    }

    /**
     * Build a Process from an argv array (no shell), injecting PGPASSWORD via the
     * environment only. Using the array form guarantees the password can never be
     * exposed in `ps` / argv.
     *
     * @param string[] $command
     */
    private function makeProcess(array $command): Process
    {
        $process = new Process(
            $command,
            null,
            ['PGPASSWORD' => (string) config('backup.pg.password')],
        );

        $process->setTimeout(self::PROCESS_TIMEOUT);

        return $process;
    }

    /**
     * Build the canonical S3 key: postgres-backups/{db}/{db}_{timestamp}.dump
     */
    private function s3Key(string $database, string $filename): string
    {
        $prefix = trim((string) config('backup.prefix', 'postgres-backups'), '/');

        return "{$prefix}/{$database}/{$filename}";
    }

    /**
     * Whether stderr indicates a fatal failure (substring "FATAL").
     */
    private function hasFatal(string $stderr): bool
    {
        return str_contains($stderr, 'FATAL');
    }

    /**
     * Validate a PostgreSQL database name we are about to pass to pg_restore.
     */
    private function isValidDatabaseName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1;
    }

    /**
     * The configured S3 disk used for all backup storage.
     */
    private function disk()
    {
        return Storage::disk(config('backup.disk', 's3'));
    }
}
