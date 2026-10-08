<?php

namespace App\Services\Work;

use Illuminate\Support\Facades\DB;

/**
 * The desk rules for one person: their own number when the admin set one on
 * the Staff page, otherwise the shop-wide setting. Read once per request.
 */
class DeskRules
{
    /** @var array<int, array{limit: int, timer: int, extend: int, extend_daily: int, voice: bool}> */
    private array $cache = [];

    /** @return array{limit: int, timer: int, extend: int, extend_daily: int, voice: bool} */
    public function for(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }
        $own = DB::table('users')->where('id', $userId)
            ->first(['desk_limit', 'desk_timer_minutes', 'desk_extend_minutes', 'desk_extend_daily_limit', 'desk_voice']);

        return $this->cache[$userId] = [
            'limit' => (int) ($own?->desk_limit ?? settings('desk.active_limit')),
            'timer' => (int) ($own?->desk_timer_minutes ?? settings('desk.action_timer_minutes')),
            'extend' => (int) ($own?->desk_extend_minutes ?? settings('desk.extend_minutes')),
            'extend_daily' => (int) ($own?->desk_extend_daily_limit ?? settings('desk.extend_daily_limit')),
            'voice' => (bool) ($own?->desk_voice ?? settings('desk.voice_alerts')),
        ];
    }

    public function limit(int $userId): int
    {
        return $this->for($userId)['limit'];
    }

    /** After a Staff page save. */
    public function forget(int $userId): void
    {
        unset($this->cache[$userId]);
    }
}
