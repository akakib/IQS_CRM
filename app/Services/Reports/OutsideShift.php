<?php

namespace App\Services\Reports;

use App\Services\Work\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orders a person confirmed or made by hand outside their shift (the shift as it
 * was set on that day; a night shift past midnight counts as on duty). Shows
 * whether overtime was real work, order by order. Two queries for everyone.
 */
class OutsideShift
{
    public function __construct(private WorkCalendar $calendar) {}

    /** @return Collection<int, Collection<int, array{id: int, order_no: string, at: Carbon, kind: string}>> user id => orders, oldest first */
    public function orders(array $userIds, Carbon $from, Carbon $to): Collection
    {
        if (! $userIds) {
            return collect();
        }
        $this->calendar->preload($userIds);
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $confirmed = DB::table('orders')->whereIn('confirmed_by', $userIds)->whereBetween('confirmed_at', $range)
            ->get(['id', 'order_no', 'confirmed_by as user_id', 'confirmed_at as at'])->map(fn ($r) => (array) $r + ['kind' => 'confirmed']);
        $made = DB::table('orders')->whereIn('created_by', $userIds)->whereBetween('created_at', $range)
            ->get(['id', 'order_no', 'created_by as user_id', 'created_at as at'])->map(fn ($r) => (array) $r + ['kind' => 'made']);

        return $confirmed->concat($made)
            ->map(fn ($r) => ['id' => (int) $r['id'], 'order_no' => $r['order_no'], 'user_id' => (int) $r['user_id'], 'at' => Carbon::parse($r['at']), 'kind' => $r['kind']])
            ->reject(fn ($r) => $this->calendar->isWorking($r['user_id'], $r['at']))
            ->sortBy('at')->groupBy('user_id');
    }
}
