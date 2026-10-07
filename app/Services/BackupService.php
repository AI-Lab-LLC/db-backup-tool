<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\Restore;
use Illuminate\Support\Facades\Log;
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
     * A 'running' backup row (or a queued/running restore row) older than this
     * (minutes) is considered dead. Must stay above RunBackupJob::$timeout and
     * RunRestoreJob::$timeout (5400s = 90 min); guarded by a test.
     */
    public const STALE_RUNNING_MINUTES = 150;

    public const STALE_RUNNING_ERROR = 'stale: worker died / timed out';

    /**
     * Databases a restore may never target (besides the panel's own metadata
     * database, read from config at call time).
     */
    public const RESTORE_DENYLIST = ['postgres', 'template0', 'template1'];

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
            $this->bin('psql'),
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
     * unique temp file under config('backup.tmp_dir'), uploads it to
     * postgres-backups/{db}/{db}_{timestamp}.dump, verifies the uploaded object
     * size matches the local dump, then marks the row success.
     *
     * The row is marked failed on: a non-zero exit / FATAL stderr, a missing or
     * empty dump file, put() returning false (the s3 disk has 'throw' => false),
     * or a remote/local size mismatch. A partially uploaded object is deleted on
     * a best-effort basis. The temp file is always removed in a finally block.
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

        // Same-second S3 key collision guard. RunBackupJob is ShouldBeUnique per
        // database, so this should never trigger; if it does, the older row owns
        // the key and this one fails WITHOUT a key (s3_path '') so that neither
        // its cleanup nor a later prune can delete the other row's object.
        $collides = Backup::query()
            ->where('s3_path', $s3Path)
            ->where('id', '<', $backup->id)
            ->exists();

        if ($collides) {
            return $this->markFailed($backup, "S3 key collision: {$s3Path} is already used by another backup started in the same second", ['s3_path' => '']);
        }

        // Unique per row, so concurrent runs can never share a temp file.
        $tmpFile = null;
        $uploadAttempted = false;

        try {
            $tmpFile = $this->tmpDir() . DIRECTORY_SEPARATOR . "backup_{$backup->id}_{$filename}";

            $process = $this->makeProcess([
                $this->bin('pg_dump'),
                '-Fc',                 // custom (compressed) archive format
                ...$this->connectionArgs(),
                '-d', $database,
                '-f', $tmpFile,
            ]);

            $process->run();

            $stderr = trim($process->getErrorOutput());

            if (! $process->isSuccessful() || $this->hasFatal($stderr)) {
                return $this->markFailed($backup, $stderr !== '' ? $stderr : 'pg_dump exited with a non-zero status');
            }

            clearstatcache(true, $tmpFile);
            $localSize = is_file($tmpFile) ? (int) filesize($tmpFile) : 0;

            if ($localSize <= 0) {
                return $this->markFailed($backup, 'pg_dump reported success but the dump file is missing or empty');
            }

            // Make sure the archive is readable (TOC parses) before it becomes
            // "the backup": a corrupt dump must never be uploaded as success.
            $validationError = $this->validateArchive($tmpFile);

            if ($validationError !== null) {
                return $this->markFailed($backup, "dump archive validation failed: {$validationError}");
            }

            // Stream the dump to S3 (avoids loading the whole file into memory).
            $stream = fopen($tmpFile, 'rb');
            if ($stream === false) {
                return $this->markFailed($backup, "Could not open dump file for upload: {$tmpFile}");
            }

            $uploadAttempted = true;

            try {
                $uploaded = $this->disk()->put($s3Path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($uploaded === false) {
                $this->deleteObjectQuietly($s3Path);

                return $this->markFailed($backup, "S3 upload failed: put() returned false for {$s3Path}");
            }

            try {
                $remoteSize = $this->disk()->size($s3Path);
            } catch (\Throwable $e) {
                $remoteSize = null;
            }

            if (! is_int($remoteSize) || $remoteSize !== $localSize) {
                $this->deleteObjectQuietly($s3Path);

                $remote = is_int($remoteSize) ? "{$remoteSize} bytes" : 'unavailable';

                return $this->markFailed($backup, "S3 upload verification failed for {$s3Path}: local {$localSize} bytes, remote {$remote}");
            }

            $backup->update([
                'size_bytes' => $localSize,
                's3_path' => $s3Path,
                'filename' => $filename,
                'status' => 'success',
                'finished_at' => now(),
            ]);

            return $backup;
        } catch (\Throwable $e) {
            if ($uploadAttempted) {
                $this->deleteObjectQuietly($s3Path);
            }

            return $this->markFailed($backup, $e->getMessage());
        } finally {
            if ($tmpFile !== null && is_file($tmpFile)) {
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
        // Static guards (name format + denylist incl. the panel's own DB) are
        // re-checked here so no caller can bypass them; existence on the server
        // is checked by restoreTargetError() before the restore is queued.
        $error = self::staticRestoreTargetError($targetDatabase);

        if ($error !== null) {
            throw new RuntimeException($error);
        }

        $tmpFile = $this->tmpDir() . DIRECTORY_SEPARATOR . 'restore_' . $backup->id . '_' . basename($backup->filename);

        try {
            // Stream the dump down from S3 (never buffer the whole file in memory).
            $this->downloadToFile($backup->s3_path, $tmpFile);

            // A dropped connection yields a short (not failed) copy, and
            // pg_restore on a truncated archive errors without "FATAL" — so
            // verify completeness BEFORE the destructive --clean runs.
            $this->assertDownloadComplete($backup, $tmpFile);

            $process = $this->makeProcess([
                $this->bin('pg_restore'),
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
     * Why $target may NOT be used as a restore target, or null when allowed.
     *
     * Rejected: names not matching /^[A-Za-z0-9_]+$/, the panel's own metadata
     * database (config database.connections.pgsql.database), postgres /
     * template0 / template1, and databases that do not exist on the server.
     * Existence is checked with a fresh listDatabases() call (never cached).
     *
     * @return string|null a human-readable (Russian, UI-facing) reason, or null
     */
    public function restoreTargetError(string $target): ?string
    {
        $error = self::staticRestoreTargetError($target);

        if ($error !== null) {
            return $error;
        }

        try {
            $exists = in_array($target, $this->listDatabases(), true);
        } catch (\Throwable $e) {
            return "не удалось проверить наличие базы «{$target}» на сервере PostgreSQL: " . $e->getMessage();
        }

        return $exists ? null : "база «{$target}» не существует на сервере PostgreSQL";
    }

    /**
     * Whether $target is an allowed restore target (see restoreTargetError()).
     */
    public function isRestoreTargetAllowed(string $target): bool
    {
        return $this->restoreTargetError($target) === null;
    }

    /**
     * Mark restores stuck in queued/running longer than STALE_RUNNING_MINUTES
     * as failed. Such a row can only belong to a lost job or a dead worker, and
     * would otherwise block scheduled backups of its target database forever.
     *
     * @return int number of rows marked failed
     */
    public function markStaleRestores(): int
    {
        $threshold = now()->subMinutes(self::STALE_RUNNING_MINUTES);

        return Restore::query()
            ->whereIn('status', [Restore::STATUS_QUEUED, Restore::STATUS_RUNNING])
            ->where(fn ($q) => $q->where('started_at', '<', $threshold)
                ->orWhere(fn ($q) => $q->whereNull('started_at')->where('created_at', '<', $threshold)))
            ->update([
                'status' => Restore::STATUS_FAILED,
                'error' => self::STALE_RUNNING_ERROR,
                'finished_at' => now(),
            ]);
    }

    /**
     * Delete backups for a database older than the retention window (any status:
     * success, failed, or long-dead running rows), removing the S3 object and the
     * history row.
     *
     * A row is deleted ONLY when its S3 object is confirmed gone: delete()
     * returned true, the object no longer exists, or the row has no s3_path. On
     * failure the row is kept (so the object stays visible/retryable) and a
     * warning is logged. An object still referenced by a surviving row (e.g. a
     * shared key) is never deleted — only the stale row is dropped.
     *
     * The newest success row for the database (and its object) is ALWAYS kept.
     *
     * A non-positive retention means "keep everything" — nothing is deleted.
     *
     * @return array{deleted: int, kept: int}
     */
    public function pruneOld(string $database, int $retentionDays): array
    {
        $summary = ['deleted' => 0, 'kept' => 0];

        if ($retentionDays <= 0) {
            return $summary;
        }

        $cutoff = now()->subDays($retentionDays);

        // Never prune the newest successful backup, however old: if backups
        // have been failing (or the DB is disabled / manual-only) longer than
        // the retention window, it is the only restorable dump left.
        $newestSuccessId = Backup::query()
            ->where('database_name', $database)
            ->where('status', 'success')
            ->max('id');

        // Backups a queued/running restore still reads from are kept until it
        // finishes (the next prune picks them up).
        $inUseByRestore = Restore::query()->active()->whereNotNull('backup_id')->pluck('backup_id')->all();

        $expired = Backup::query()
            ->where('database_name', $database)
            ->where('created_at', '<', $cutoff)
            ->when($newestSuccessId !== null, fn ($q) => $q->where('id', '!=', $newestSuccessId))
            ->when($inUseByRestore !== [], fn ($q) => $q->whereNotIn('id', $inUseByRestore))
            ->orderBy('id')
            ->get();

        foreach ($expired as $backup) {
            // Objects still referenced by a row that survives this prune (any
            // success row, or anything inside the window) are never deleted.
            $removed = $this->removeBackup($backup, fn ($q) => $q
                ->where('status', 'success')
                ->orWhere('created_at', '>=', $cutoff));

            $removed ? $summary['deleted']++ : $summary['kept']++;
        }

        return $summary;
    }

    /**
     * Delete a single backup (UI "delete"): S3 object first, history row only
     * once the object is confirmed gone. An object that any other row still
     * references is left in place and only this row is removed.
     *
     * @return bool true if the row was deleted, false if the row is still
     *              'running' or the S3 delete failed (the row is kept)
     */
    public function deleteBackup(Backup $backup): bool
    {
        // An in-flight backup will still upload its object; deleting the row
        // now would orphan that object (prune only iterates rows).
        if ($backup->status === 'running') {
            return false;
        }

        return $this->removeBackup($backup, fn ($q) => $q);
    }

    /**
     * Mark rows stuck in 'running' longer than STALE_RUNNING_MINUTES as failed.
     *
     * The threshold (150 min) is well above RunBackupJob's timeout (5400s), so such a
     * row can only belong to a worker that died or was killed.
     *
     * @return int number of rows marked failed
     */
    public function markStaleRunning(): int
    {
        $threshold = now()->subMinutes(self::STALE_RUNNING_MINUTES);

        return Backup::query()
            ->where('status', 'running')
            ->where(fn ($q) => $q->where('started_at', '<', $threshold)
                ->orWhere(fn ($q) => $q->whereNull('started_at')->where('created_at', '<', $threshold)))
            ->update([
                'status' => 'failed',
                'error' => self::STALE_RUNNING_ERROR,
                'finished_at' => now(),
            ]);
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
            '-w', // --no-password: a wrong/missing PGPASSWORD fails fast instead of prompting
        ];
    }

    /**
     * Resolve a PostgreSQL client binary: "{backup.pg.bin_dir}/{name}" when
     * configured (pin the client major version to the server's), else the bare
     * name looked up on PATH.
     */
    private function bin(string $name): string
    {
        $dir = rtrim(trim((string) config('backup.pg.bin_dir', '')), '/');

        return $dir === '' ? $name : "{$dir}/{$name}";
    }

    /**
     * Run `pg_restore --list` on a local dump to confirm the archive is
     * readable. No connection is made, so no connection args are passed.
     *
     * @return string|null failure detail, or null when the archive is valid
     */
    private function validateArchive(string $file): ?string
    {
        $process = $this->makeProcess([
            $this->bin('pg_restore'),
            '--list',
            $file,
        ]);

        $process->run();

        $stderr = trim($process->getErrorOutput());

        if ($process->isSuccessful() && ! $this->hasFatal($stderr)) {
            return null;
        }

        return $stderr !== '' ? $stderr : 'pg_restore --list exited with status ' . $process->getExitCode();
    }

    /**
     * Restore-target checks that need no server round-trip (name format,
     * system databases, the panel's own metadata DB). Static so views/lists can
     * filter with it without a service instance.
     */
    public static function staticRestoreTargetError(string $target): ?string
    {
        if ($target === '' || preg_match('/^[A-Za-z0-9_]+$/', $target) !== 1) {
            return "недопустимое имя базы «{$target}» (разрешены только A-Z, a-z, 0-9, _)";
        }

        if (in_array(strtolower($target), self::RESTORE_DENYLIST, true)) {
            return "восстановление в системную базу «{$target}» запрещено";
        }

        // Resolved through the connection so DB_URL-based setups are covered too.
        try {
            $panelDb = (string) \Illuminate\Support\Facades\DB::connection('pgsql')->getDatabaseName();
        } catch (\Throwable) {
            $panelDb = (string) config('database.connections.pgsql.database');
        }

        if ($panelDb !== '' && strcasecmp($target, $panelDb) === 0) {
            return "восстановление в служебную базу панели «{$target}» запрещено";
        }

        return null;
    }

    /**
     * Build a Process from an argv array (no shell), injecting PGPASSWORD via the
     * environment only. Using the array form guarantees the password can never be
     * exposed in `ps` / argv.
     *
     * Protected only so tests can substitute a harmless process (pg_* binaries
     * are not available locally); production code must never bypass it.
     *
     * @param string[] $command
     */
    protected function makeProcess(array $command): Process
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
     * Shared delete logic for pruneOld() / deleteBackup().
     *
     * The S3 object is deleted unless another row matching $survivors still
     * references the same s3_path. The row is deleted only when the object is
     * confirmed gone (or not ours to delete); otherwise a warning is logged and
     * the row is kept.
     *
     * @param callable(\Illuminate\Database\Eloquent\Builder): mixed $survivors
     */
    private function removeBackup(Backup $backup, callable $survivors): bool
    {
        $path = (string) $backup->s3_path;

        $shared = $path !== '' && Backup::query()
            ->where('s3_path', $path)
            ->where('id', '!=', $backup->id)
            ->where(fn ($q) => $survivors($q))
            ->exists();

        if ($path !== '' && ! $shared && ! $this->deleteObject($path)) {
            Log::warning('Backup delete: S3 delete failed, keeping history row', [
                'backup_id' => $backup->id,
                'database' => $backup->database_name,
                's3_path' => $path,
            ]);

            return false;
        }

        $backup->delete();

        return true;
    }

    /**
     * Mark a backup row failed with a clear error message.
     *
     * @param array<string, mixed> $extra
     */
    private function markFailed(Backup $backup, string $error, array $extra = []): Backup
    {
        $backup->update(array_merge([
            'status' => 'failed',
            'error' => $error,
            'finished_at' => now(),
        ], $extra));

        return $backup;
    }

    /**
     * Resolve (and create if needed) the temp directory for dumps/restores.
     * Configurable via BACKUP_TMP_DIR so it can live outside Forge releases.
     */
    private function tmpDir(): string
    {
        $dir = rtrim((string) config('backup.tmp_dir', storage_path('app/tmp')), DIRECTORY_SEPARATOR);

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (! is_dir($dir) || ! is_writable($dir)) {
            throw new RuntimeException("Backup temp dir is not writable: {$dir}");
        }

        return $dir;
    }

    /**
     * Stream an S3 object into a local file.
     *
     * @throws RuntimeException when the object cannot be read or written
     */
    private function downloadToFile(string $s3Path, string $localPath): void
    {
        $in = $this->disk()->readStream($s3Path);

        if (! is_resource($in)) {
            throw new RuntimeException("Dump not found on S3: {$s3Path}");
        }

        try {
            $out = fopen($localPath, 'wb');
            if ($out === false) {
                throw new RuntimeException("Could not open temp file for writing: {$localPath}");
            }

            try {
                if (stream_copy_to_stream($in, $out) === false) {
                    throw new RuntimeException("Failed to download dump from S3: {$s3Path}");
                }
            } finally {
                fclose($out);
            }
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
    }

    /**
     * Ensure the downloaded dump has the expected size: the S3 object size, or
     * the recorded size_bytes when the object size can't be read.
     *
     * @throws RuntimeException on mismatch or when no expected size is known
     */
    private function assertDownloadComplete(Backup $backup, string $tmpFile): void
    {
        clearstatcache(true, $tmpFile);
        $local = is_file($tmpFile) ? (int) filesize($tmpFile) : -1;

        try {
            $expected = $this->disk()->size($backup->s3_path);
        } catch (\Throwable $e) {
            $expected = null;
        }

        if (! is_int($expected) || $expected <= 0) {
            $expected = $backup->size_bytes !== null ? (int) $backup->size_bytes : null;
        }

        if ($expected === null || $expected <= 0) {
            throw new RuntimeException("Cannot verify downloaded dump size for {$backup->s3_path}; restore aborted");
        }

        if ($local !== $expected) {
            throw new RuntimeException("Downloaded dump is incomplete ({$local} of {$expected} bytes) for {$backup->s3_path}; restore aborted before pg_restore");
        }
    }

    /**
     * Delete an S3 object and confirm it is gone.
     *
     * True when delete() succeeded, or when the object no longer exists (e.g. it
     * was already missing). False on any failure to delete/confirm.
     */
    private function deleteObject(string $s3Path): bool
    {
        try {
            if ($this->disk()->delete($s3Path) === true) {
                return true;
            }
        } catch (\Throwable $e) {
            // fall through to the existence check
        }

        try {
            return ! $this->disk()->exists($s3Path);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Best-effort removal of a partial / unverified upload. Never throws.
     */
    private function deleteObjectQuietly(string $s3Path): void
    {
        if (! $this->deleteObject($s3Path)) {
            Log::warning('Backup: could not delete partial S3 object', ['s3_path' => $s3Path]);
        }
    }

    /**
     * The configured S3 disk used for all backup storage.
     */
    private function disk()
    {
        return Storage::disk(config('backup.disk', 's3'));
    }
}
