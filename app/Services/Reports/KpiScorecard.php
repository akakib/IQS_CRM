<?php

namespace App\Services\Reports;

use App\Models\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * KPI per moderator: counts and rates, no weighted score. Separate from points.
 *
 * Two views of the same period:
 *   day     what happened in the period (delivered / cancelled / returned on those days)
 *   cohort  orders TAKEN in the period and where they are now (delivered, cancelled,
 *           returned, the rest still on the way)
 *
 * Delivered = the courier confirmed delivery (our Delivered status is only set then).
 * Rates use ended orders only: delivered / (delivered + cancelled + returned).
 */
class KpiScorecard
{
    public const METRICS = ['delivered_count' => 'Delivered orders per month', 'delivery_rate' => 'Delivery rate (%)'];

    private const EMPTY = ['delivered' => 0, 'cancelled' => 0, 'returned' => 0, 'open' => 0, 'saved' => 0, 'amount' => 0.0];

    /** @return array{rows: list<array<string, mixed>>, team: array<string, mixed>} */
    public function build(string $from, string $to, string $mode = 'day'): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $s = fn (string $key) => OrderStatus::idFor($key);
        $delivered = [$s('delivered'), $s('partial_delivered')];
        $ended = [...$delivered, $s('cancelled'), $s('returned')];

        if ($mode === 'cohort') {
            $stats = DB::table('orders as o')->whereNotNull('o.moderator_id')->whereBetween('o.assigned_at', $range);
        } else {
            // One row per order, even if a courier correction logged its final status twice.
            $stats = DB::table('orders as o')->whereRaw('COALESCE(o.confirmed_by, o.moderator_id) IS NOT NULL')->whereIn('o.status_id', $ended)
                ->whereExists(fn ($q) => $q->from('order_events as e')->whereColumn('e.order_id', 'o.id')->whereColumn('e.to_status_id', 'o.status_id')->whereBetween('e.created_at', $range));
        }
        // Ended orders count for who confirmed them (a later reassign does not move them); the cohort view follows the holder.
        $who = $mode === 'cohort' ? 'o.moderator_id' : 'COALESCE(o.confirmed_by, o.moderator_id)';
        $stats = $stats->groupByRaw("$who, o.status_id")
            ->selectRaw("$who as moderator_id, o.status_id, COUNT(*) as n, SUM(CASE WHEN o.had_setback = 1 THEN 1 ELSE 0 END) as setbacks, SUM(o.grand_total) as amount")->get();

        $by = [];
        foreach ($stats as $r) {
            $u = $by[$r->moderator_id] ?? self::EMPTY;
            if (in_array((int) $r->status_id, $delivered, true)) {
                $u['delivered'] += (int) $r->n;
                $u['saved'] += (int) $r->setbacks;
                $u['amount'] += (float) $r->amount;
            } elseif ((int) $r->status_id === $s('cancelled')) {
                $u['cancelled'] += (int) $r->n;
            } elseif ((int) $r->status_id === $s('returned')) {
                $u['returned'] += (int) $r->n;
            } else {
                $u['open'] += (int) $r->n;
            }
            $by[$r->moderator_id] = $u;
        }

        $work = DB::table('order_assignments')->whereBetween('started_at', $range)->groupBy('user_id')
            ->selectRaw("user_id, COUNT(DISTINCT order_id) as taken, SUM(CASE WHEN ended_reason IN ('timeout', 'idle') THEN 1 ELSE 0 END) as released")->get()->keyBy('user_id');
        $pending = DB::table('orders')->where('status_id', $s('no_answer'))->whereNotNull('moderator_id')
            ->groupBy('moderator_id')->selectRaw('moderator_id, COUNT(*) as n')->pluck('n', 'moderator_id');

        $ids = array_values(array_unique(array_merge(array_keys($by), $work->keys()->all(), $pending->keys()->all())));
        if ($ids === []) {
            return ['rows' => [], 'team' => self::EMPTY + ['taken' => 0, 'released' => 0, 'pending' => 0] + $this->rates(self::EMPTY)];
        }
        $names = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');
        $targets = $this->targets($ids);

        $rows = [];
        $sum = self::EMPTY + ['taken' => 0, 'released' => 0, 'pending' => 0];
        foreach ($ids as $id) {
            $u = ($by[$id] ?? self::EMPTY) + [
                'taken' => (int) ($work[$id]->taken ?? 0), 'released' => (int) ($work[$id]->released ?? 0), 'pending' => (int) ($pending[$id] ?? 0),
            ];
            foreach ($sum as $k => $v) {
                $sum[$k] += $u[$k];
            }
            $rows[] = ['user_id' => $id, 'name' => $names[$id] ?? '#'.$id] + $u + $this->rates($u) + [
                'target_count' => $targets[$id]['delivered_count'] ?? $targets[0]['delivered_count'] ?? null,
                'target_rate' => $targets[$id]['delivery_rate'] ?? $targets[0]['delivery_rate'] ?? null,
            ];
        }
        usort($rows, fn ($a, $b) => [$b['delivered'], $b['delivery_rate'] ?? -1] <=> [$a['delivered'], $a['delivery_rate'] ?? -1]);

        $n = count($rows);
        $team = [];
        foreach ($sum as $k => $v) {
            $team[$k] = round($v / $n, 1);
        }

        return ['rows' => $rows, 'team' => $team + $this->rates($sum)];
    }

    /** @return array<int, array<string, float>> user id (0 = default for everyone) => metric => target */
    public function targets(?array $userIds = null): array
    {
        $out = [];
        $rows = DB::table('kpi_targets')->when($userIds !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('user_id')->orWhereIn('user_id', $userIds)))->get();
        foreach ($rows as $t) {
            $out[(int) $t->user_id][$t->metric] = (float) $t->target;
        }

        return $out;
    }

    /** @return array{delivery_rate: ?float, cancel_rate: ?float, return_rate: ?float} */
    private function rates(array $u): array
    {
        $ended = $u['delivered'] + $u['cancelled'] + $u['returned'];
        $pct = fn (int|float $n) => $ended ? round(100 * $n / $ended, 1) : null;

        return ['delivery_rate' => $pct($u['delivered']), 'cancel_rate' => $pct($u['cancelled']), 'return_rate' => $pct($u['returned'])];
    }
}
