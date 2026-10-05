<?php

namespace App\Services\Reports;

use App\Models\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KPI: order volume, speed and quality per order owner, from order_events
 * (the append-only history). Separate from points by design.
 *
 *   volume  = confirmed / the team's highest confirmed
 *   speed   = team's fastest median (take -> confirm) / own median
 *   quality = delivered / (delivered + returned + cancelled)
 * Score = weighted sum (Settings > KPI weights).
 */
class KpiScorecard
{
    /** @return list<array<string, mixed>> sorted by score */
    public function rows(string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $counts = DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')
            ->whereNotNull('o.owner_id')->whereBetween('e.created_at', $range)
            ->whereIn('e.to_status_id', OrderStatus::idsFor(['confirmed', 'delivered', 'partial_delivered', 'returned', 'cancelled']))
            ->groupBy('o.owner_id', 'e.to_status_id')
            ->selectRaw('o.owner_id, e.to_status_id, COUNT(DISTINCT e.order_id) as n')->get();

        $taken = DB::table('order_assignments')->whereBetween('started_at', $range)->whereIn('how', ['claimed', 'created', 'reassigned'])
            ->groupBy('user_id')->selectRaw('user_id, COUNT(DISTINCT order_id) as n')->pluck('n', 'user_id');

        // Take -> confirm minutes for orders confirmed in the range (by a person, not a rule).
        $speeds = DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')
            ->join('order_assignments as a', fn ($j) => $j->on('a.order_id', '=', 'o.id')->on('a.user_id', '=', 'o.owner_id'))
            ->where('e.to_status_id', OrderStatus::idFor('confirmed'))->where('e.source', 'user')->whereBetween('e.created_at', $range)
            ->get(['o.owner_id', 'e.order_id', 'e.created_at', 'a.started_at'])
            ->groupBy('owner_id')->map(fn ($rows) => $this->median($rows->unique('order_id')
                ->map(fn ($r) => max(0, Carbon::parse($r->started_at)->diffInSeconds(Carbon::parse($r->created_at), false) / 60))->all()));

        $byUser = [];
        foreach ($counts as $c) {
            $status = OrderStatus::map()[$c->to_status_id]['key'];
            $byUser[$c->owner_id][$status] = (int) $c->n;
        }
        $userIds = array_unique(array_merge(array_keys($byUser), $taken->keys()->all()));
        if ($userIds === []) {
            return [];
        }
        $names = DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id');

        $w = ['volume' => (int) settings('kpi.weight_volume'), 'speed' => (int) settings('kpi.weight_speed'), 'quality' => (int) settings('kpi.weight_quality')];
        $wSum = max(1, array_sum($w));
        $maxConfirmed = max(1, ...array_map(fn ($u) => $byUser[$u]['confirmed'] ?? 0, $userIds));
        $fastest = $speeds->filter(fn ($m) => $m !== null && $m > 0)->min();

        $rows = [];
        foreach ($userIds as $id) {
            $c = $byUser[$id] ?? [];
            $delivered = ($c['delivered'] ?? 0) + ($c['partial_delivered'] ?? 0);
            $ended = $delivered + ($c['returned'] ?? 0) + ($c['cancelled'] ?? 0);
            $median = $speeds[$id] ?? null;

            $volume = 100 * ($c['confirmed'] ?? 0) / $maxConfirmed;
            $speed = $median === null ? 0 : ($median <= 0 || ! $fastest ? 100 : 100 * $fastest / $median);
            $quality = $ended ? 100 * $delivered / $ended : 0;

            $rows[] = [
                'user_id' => $id, 'name' => $names[$id] ?? '#'.$id,
                'taken' => (int) ($taken[$id] ?? 0), 'confirmed' => $c['confirmed'] ?? 0, 'delivered' => $delivered,
                'returned' => $c['returned'] ?? 0, 'cancelled' => $c['cancelled'] ?? 0,
                'median_minutes' => $median, 'delivered_rate' => $ended ? round(100 * $delivered / $ended, 1) : null,
                'volume' => round($volume), 'speed' => round($speed), 'quality' => round($quality),
                'score' => round(($volume * $w['volume'] + $speed * $w['speed'] + $quality * $w['quality']) / $wSum, 1),
            ];
        }
        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $rows;
    }

    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }
}
