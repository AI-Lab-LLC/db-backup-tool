<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mutex expiries are explicit: a crashed run must not hold the overlap lock for
// the default 24h (that would silently stop scheduled backups).
Schedule::command('backups:dispatch')->everyMinute()->withoutOverlapping(10);

// Retention + stale-running cleanup (RunBackupJob still prunes after each success).
Schedule::command('backups:prune')->daily()->withoutOverlapping(120);

// Alert when an enabled scheduled database has no success within 2x its interval.
Schedule::command('backups:check-stale')->hourly()->withoutOverlapping(30);
