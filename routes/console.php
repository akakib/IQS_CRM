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

// Shop team end-of-day report on Telegram.
Schedule::command('telegram:shop-summary')->dailyAt('21:00');

// Delivery issues past their SLA go to managers.
Schedule::command('issues:escalate')->everyFiveMinutes();

// Webhook fallback for parcels still on the way.
Schedule::command('courier:resync')->everyThirtyMinutes()->withoutOverlapping(20);

// Product changes -> website (one-way), with retry/backoff inside.
Schedule::command('channel-sync:run')->everyMinute()->withoutOverlapping(5);

// Access already stops at expires_at; this only clears the expired rows.
Schedule::command('permissions:prune-expired')->hourly();

// Owner's nightly numbers on Telegram, at the time set in Settings.
Schedule::command('reports:owner-summary')->everyMinute()
    ->when(fn () => now()->format('H:i') === (string) settings('reports.owner_summary_time'));

// Ad spend (last 3 days re-pulled) and FIFO dollar cost.
Schedule::command('ads:pull-spend')->everyThreeHours()->withoutOverlapping(30);

// Dollar vendor balances due by tomorrow.
Schedule::command('vendors:due-reminders')->dailyAt('10:00');

// Order desk: timers, auto-assign, booking retries, breaks left open, packaging digest.
Schedule::command('desk:tick')->everyMinute()->withoutOverlapping(5);
