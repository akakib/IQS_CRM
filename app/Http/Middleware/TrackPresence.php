<?php

namespace App\Http\Middleware;

use App\Services\Work\WorkCalendar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two small jobs for every signed-in request:
 *  1. Presence: at most once a minute, note that the person is here
 *     (users.last_seen_at + today's work_days row). The bell's background
 *     poll does not count, so an idle open tab is not "active".
 *  2. Break lock: while on a break nothing can be changed except ending it.
 */
class TrackPresence
{
    private const BACKGROUND = ['notifications.count', 'notifications.feed'];

    private const ALLOWED_ON_BREAK = ['breaks.end', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        $route = $request->route()?->getName();

        if ($user->current_break_id && ! $request->isMethodSafe() && ! in_array($route, self::ALLOWED_ON_BREAK, true)) {
            return $request->expectsJson()
                ? response()->json(['message' => __('You are on a break. Press Start work first.')], 423)
                : back()->with('error', __('You are on a break. Press Start work first.'));
        }

        if (! in_array($route, self::BACKGROUND, true) && (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute()))) {
            $now = now();
            DB::table('users')->where('id', $user->id)->update(['last_seen_at' => $now]);
            $user->last_seen_at = $now;

            $today = $now->toDateString();
            if (! DB::table('work_days')->where('user_id', $user->id)->where('work_date', $today)->update(['last_seen_at' => $now])) {
                DB::table('work_days')->insertOrIgnore([
                    'user_id' => $user->id, 'work_date' => $today, 'first_seen_at' => $now, 'last_seen_at' => $now,
                    'is_extra' => app(WorkCalendar::class)->isOffDay($user->id, $now),
                ]);
            }
        }

        return $next($request);
    }
}
