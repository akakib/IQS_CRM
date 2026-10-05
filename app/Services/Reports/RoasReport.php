<?php

namespace App\Services\Reports;

use App\Models\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per day: ad spend (USD and real BDT from FIFO) against the orders placed
 * that day. Three ROAS views, because Meta's own number counts purchases
 * that never get delivered:
 *   Meta ROAS       = value Meta reports / BDT cost
 *   Confirmed ROAS  = confirmed revenue / BDT cost
 *   Delivered ROAS  = delivered revenue / BDT cost (the real one)
 *   MER (blended)   = all placed revenue / BDT cost
 * B2B orders are left out: ads do not bring them.
 */
class RoasReport
{
    /** @return array{days: list<array<string, mixed>>, totals: array<string, mixed>} newest day first */
    public function build(string $from, string $to): array
    {
        $spend = DB::table('ad_spend_daily')->whereBetween('spend_date', [$from, $to])->groupBy('spend_date')
            ->selectRaw('spend_date, SUM(spend_usd) as usd, SUM(bdt_cost) as bdt, SUM(messages) as messages, SUM(reported_value) as reported, SUM(unfunded_usd) as unfunded')
            ->get()->keyBy(fn ($r) => substr((string) $r->spend_date, 0, 10));

        $confirmedOrLater = implode(',', array_map('intval', OrderStatus::idsFor(['confirmed', 'ready_for_packaging', 'packed', 'ready_for_pickup', 'handed_over', 'in_transit', 'delivered', 'partial_delivered']))) ?: '0';
        $delivered = implode(',', array_map('intval', OrderStatus::idsFor(['delivered', 'partial_delivered']))) ?: '0';

        $orders = DB::table('orders')->where('channel', '!=', 'b2b')->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupByRaw('DATE(created_at)')
            ->selectRaw("DATE(created_at) as d, COUNT(*) as orders,
                SUM(CASE WHEN channel = 'web' THEN 1 ELSE 0 END) as web,
                SUM(CASE WHEN channel = 'messenger' THEN 1 ELSE 0 END) as messenger,
                SUM(CASE WHEN channel IN ('whatsapp', 'phone') THEN 1 ELSE 0 END) as other,
                SUM(grand_total) as placed,
                SUM(CASE WHEN status_id IN ($confirmedOrLater) THEN grand_total ELSE 0 END) as confirmed,
                SUM(CASE WHEN status_id IN ($delivered) THEN grand_total ELSE 0 END) as delivered")
            ->get()->keyBy(fn ($r) => substr((string) $r->d, 0, 10));

        $days = [];
        $sum = array_fill_keys(['usd', 'bdt', 'messages', 'reported', 'unfunded', 'orders', 'web', 'messenger', 'other', 'placed', 'confirmed', 'delivered'], 0.0);
        for ($d = Carbon::parse($to); $d->gte(Carbon::parse($from)); $d->subDay()) {
            $k = $d->toDateString();
            $s = $spend[$k] ?? null;
            $o = $orders[$k] ?? null;
            if (! $s && ! $o) {
                continue;
            }
            $row = ['day' => $k];
            foreach (['usd', 'bdt', 'messages', 'reported', 'unfunded'] as $f) {
                $row[$f] = (float) ($s->{$f} ?? 0);
            }
            foreach (['orders', 'web', 'messenger', 'other', 'placed', 'confirmed', 'delivered'] as $f) {
                $row[$f] = (float) ($o->{$f} ?? 0);
            }
            foreach ($sum as $f => $v) {
                $sum[$f] += $row[$f];
            }
            $days[] = $row + $this->ratios($row);
        }

        return ['days' => $days, 'totals' => $sum + $this->ratios($sum)];
    }

    private function ratios(array $r): array
    {
        $per = fn ($a, $b) => $b > 0 ? round($a / $b, 2) : null;

        return [
            'meta_roas' => $per($r['reported'], $r['bdt']),
            'confirmed_roas' => $per($r['confirmed'], $r['bdt']),
            'delivered_roas' => $per($r['delivered'], $r['bdt']),
            'mer' => $per($r['placed'], $r['bdt']),
            'cost_per_order' => $per($r['bdt'], $r['orders']),
            'cost_per_message' => $per($r['bdt'], $r['messages']),
        ];
    }
}
