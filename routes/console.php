<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Shared hosting has no long-running workers. One cron line runs everything:
|   * * * * * cd ~/domains/iqs.top5way.com/public_html && php artisan schedule:run >> /dev/null 2>&1
*/

// Health page shows when the scheduler last ran.
Schedule::call(fn () => Cache::forever('schedule:last_run', now()->toIso8601String()))
    ->everyMinute()->name('heartbeat');

// Drain the database queue, then exit (no daemon).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()->withoutOverlapping(5);

Schedule::command('backup:database')->dailyAt('03:00');

// Product changes -> website (one-way), with retry/backoff inside.
Schedule::command('channel-sync:run')->everyMinute()->withoutOverlapping(5);

// Access already stops at expires_at; this only clears the expired rows.
Schedule::command('permissions:prune-expired')->hourly();
