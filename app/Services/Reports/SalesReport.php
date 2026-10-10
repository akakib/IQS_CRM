<?php

namespace App\Services\Reports;

use App\Models\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily sales: orders placed, completed (delivered + partial), cancelled and
 * returned per day, with the money delivered. Two grouped queries for any
 * range; days with nothing are filled with zeros so the graph is continuous.
 * Ranges over ~3 months are shown per week so the graph stays readable; a
 * single day is shown per hour.
 */
class SalesReport
{
    public const MAX_DAYS = 366;

    /**
     * @return array{rows: list<array{day: string, label: string, placed: int, placed_amount: float, completed: int, completed_amount: float, cancelled: int, returned: int}>, totals: array<string, float|int>, weekly: bool}
     */
    public function build(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            $start = $end->copy()->subDays(self::MAX_DAYS - 1);
        }
        $weekly = $start->diffInDays($end) > 92;
        $hourly = $start->equalTo($end);
        // Bucket expression: the day, or the hour of that one day.
        $bucket = fn (string $col) => $hourly ? $this->hourExpr($col) : "DATE($col)";

        $placed = DB::table('orders')
            ->whereBetween('created_at', [$start->toDateTimeString(), $end->copy()->endOfDay()->toDateTimeString()])
            ->groupByRaw($bucket('created_at'))
            ->selectRaw($bucket('created_at').' as d, COUNT(*) as n, SUM(grand_total) as amount')
            ->get()->keyBy('d');

        $keys = ['delivered', 'partial_delivered', 'cancelled', 'returned'];
        $keyById = [];
        foreach ($keys as $key) {
            $keyById[OrderStatus::idFor($key)] = $key;
        }
        $ids = array_keys($keyById);
        $done = implode(',', array_map('intval', OrderStatus::idsFor(['delivered', 'partial_delivered']))) ?: '0';
        $ended = DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')
            ->leftJoin('shipments as s', 's.id', '=', 'o.active_shipment_id')
            ->whereIn('e.to_status_id', $ids)
            ->whereBetween('e.created_at', [$start->toDateTimeString(), $end->copy()->endOfDay()->toDateTimeString()])
            ->groupByRaw($bucket('e.created_at').', e.to_status_id')
            ->selectRaw($bucket('e.created_at')." as d, e.to_status_id as status_id, COUNT(*) as n,
                SUM(CASE WHEN e.to_status_id IN ($done) THEN COALESCE(s.collected_amount, o.cod_amount) + o.advance_verified ELSE 0 END) as amount")
            ->get();
        $endedByDay = [];
        foreach ($ended as $row) {
            $key = $keyById[$row->status_id] ?? null;
            if ($key === null) {
                continue;
            }
            $endedByDay[$row->d][$key] = ['n' => (int) $row->n, 'amount' => (float) $row->amount];
        }

        $rows = [];
        // One slot per hour of the day, or per day of the range (weeks merge days).
        $slots = $hourly
            ? array_map(fn ($h) => [(string) $h, sprintf('%02d:00', $h), (string) $h], range(0, 23))
            : [];
        if (! $hourly) {
            for ($day = $start->copy(); $day <= $end; $day->addDay()) {
                $key = $weekly ? $day->copy()->startOfWeek(Carbon::SATURDAY)->toDateString() : $day->toDateString();
                $slots[] = [$day->toDateString(), $weekly ? __('Week of :d', ['d' => Carbon::parse($key)->format('d M')]) : $day->format('d M'), $key];
            }
        }
        foreach ($slots as [$d, $label, $key]) {
            $e = $endedByDay[$d] ?? [];
            $rows[$key] ??= ['day' => $key, 'label' => $label,
                'placed' => 0, 'placed_amount' => 0.0, 'completed' => 0, 'completed_amount' => 0.0, 'cancelled' => 0, 'returned' => 0];
            $r = &$rows[$key];
            $r['placed'] += (int) ($placed[$d]->n ?? 0);
            $r['placed_amount'] += (float) ($placed[$d]->amount ?? 0);
            $r['completed'] += ($e['delivered']['n'] ?? 0) + ($e['partial_delivered']['n'] ?? 0);
            $r['completed_amount'] += ($e['delivered']['amount'] ?? 0) + ($e['partial_delivered']['amount'] ?? 0);
            $r['cancelled'] += $e['cancelled']['n'] ?? 0;
            $r['returned'] += $e['returned']['n'] ?? 0;
            unset($r);
        }
        $rows = array_values($rows);

        $totals = ['placed' => 0, 'placed_amount' => 0.0, 'completed' => 0, 'completed_amount' => 0.0, 'cancelled' => 0, 'returned' => 0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $v) {
                $totals[$k] += $r[$k];
            }
        }
        $endedCount = $totals['completed'] + $totals['cancelled'] + $totals['returned'];
        $totals['completion_rate'] = $endedCount ? round($totals['completed'] * 100 / $endedCount, 1) : null;
        $totals['days'] = count($rows);

        return ['rows' => $rows, 'totals' => $totals, 'weekly' => $weekly, 'hourly' => $hourly, 'from' => $start->toDateString(), 'to' => $end->toDateString()];
    }

    /** Hour of day (0-23) as an integer, for MySQL/MariaDB and SQLite. */
    private function hourExpr(string $col): string
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? "HOUR($col)" : "CAST(strftime('%H', $col) AS INTEGER)";
    }
}
