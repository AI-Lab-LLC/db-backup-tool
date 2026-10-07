<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\BackupConfig;
use App\Services\BackupAlerter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckStaleBackups extends Command
{
    /**
     * @var string
     */
    protected $signature = 'backups:check-stale';

    /**
     * @var string
     */
    protected $description = 'Alert for enabled scheduled databases whose last successful backup is older than 2x their interval';

    /**
     * A stale database alerts once per stale episode, re-reminding at most
     * every this many hours while it stays stale.
     */
    public const REALERT_HOURS = 24;

    /**
     * Read-only check + alert. Never dispatches, runs processes or writes rows
     * (only a cache marker used for alert throttling).
     *
     * Throttling: the marker stores which "last success" the alert was about
     * (backup id, or 'never'). A new success changes that identity, so the next
     * stale episode alerts immediately; a healthy check clears the marker.
     */
    public function handle(BackupAlerter $alerter): int
    {
        $stale = 0;

        BackupConfig::query()
            ->where('enabled', true)
            ->where('interval_minutes', '>', 0)
            ->orderBy('database_name')
            ->get()
            ->each(function (BackupConfig $config) use ($alerter, &$stale) {
                $threshold = now()->subMinutes($config->interval_minutes * 2);

                $lastSuccess = Backup::query()
                    ->where('database_name', $config->database_name)
                    ->where('status', 'success')
                    ->latest('id')
                    ->first();

                $lastAt = $lastSuccess?->finished_at ?? $lastSuccess?->created_at;

                $cacheKey = self::cacheKey($config->database_name);

                if ($lastAt !== null) {
                    if ($lastAt->greaterThanOrEqualTo($threshold)) {
                        Cache::forget($cacheKey);

                        return;
                    }
                } elseif ($config->created_at === null || $config->created_at->greaterThanOrEqualTo($threshold)) {
                    // Never backed up, but configured too recently to be overdue.
                    return;
                }

                $stale++;
                $this->warn("{$config->database_name}: no successful backup since " . ($lastAt?->toDateTimeString() ?? 'ever'));

                $episode = $lastSuccess !== null ? 'success:' . $lastSuccess->id : 'never';

                if (Cache::get($cacheKey) === $episode) {
                    return; // already alerted for this episode within REALERT_HOURS
                }

                $alerter->staleDatabase($config->database_name, $config->interval_minutes, $lastAt?->toDateTimeString());
                Cache::put($cacheKey, $episode, now()->addHours(self::REALERT_HOURS));
            });

        $this->info("{$stale} stale database(s).");

        return self::SUCCESS;
    }

    public static function cacheKey(string $database): string
    {
        return 'backups:stale-alerted:' . $database;
    }
}
