<?php

namespace App\Jobs;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Cross-job mutual exclusion per PostgreSQL database.
 *
 * ShouldBeUnique locks are scoped to the job CLASS (laravel_unique_job:{class}:{id}),
 * so they cannot keep a RunBackupJob and a RunRestoreJob of the same database
 * apart. Both jobs take this lock (non-blocking) for the duration of handle().
 *
 * The TTL matches the jobs' $uniqueFor (> $timeout), so a worker killed
 * mid-job cannot block the database forever.
 */
final class DatabaseBusyLock
{
    public const TTL_SECONDS = 5600;

    public static function key(string $database): string
    {
        return "pg-db-busy:{$database}";
    }

    public static function for(string $database): Lock
    {
        return Cache::lock(self::key($database), self::TTL_SECONDS);
    }
}
