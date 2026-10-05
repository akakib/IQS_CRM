<?php

namespace App\Services\Reports;

use App\Models\OrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Order P&L for orders that reached delivered / partial / returned in a date
 * range.
 *
 *   revenue   delivered: money collected + verified advance; returned: verified advance kept
 *   cogs      cost snapshot frozen at confirmation (partial: scaled by what was collected)
 *   delivery  courier charge on the shipment (estimate: the order's delivery charge)
 *   cod_fee   courier COD fee % on the cash collected
 *   packaging per shipped order (setting)
 *   ad_cost   the order's share of its placing day's ad cost (ad_cost_days, FIFO taka)
 */
class OrderProfit
{
    public const GROUPS = ['day', 'channel', 'owner', 'product', 'category'];

    /** One row per settled order (a subquery to group on). */
    public function orders(string $from, string $to, ?string $channel = null): Builder
    {
        $delivered = OrderStatus::idsFor(['delivered', 'partial_delivered']);
        $settled = OrderStatus::idsFor(['delivered', 'partial_delivered', 'returned']);
        $pct = (float) settings('analysis.cod_fee_percent') / 100;
        $packaging = (float) settings('orders.packaging_cost');
        $in = implode(',', array_map('intval', $delivered)) ?: '0';

        $finals = DB::table('order_events')->whereIn('to_status_id', $settled)
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->groupBy('order_id')->selectRaw('order_id, MAX(created_at) as final_at');

        $collected = 'COALESCE(s.collected_amount, o.cod_amount)';
        $isDelivered = "o.status_id IN ($in)";
        $cogs = '(SELECT COALESCE(SUM(i.qty * COALESCE(i.cost_price_snapshot, 0)), 0) FROM order_items i WHERE i.order_id = o.id)';
        $share = "CASE WHEN o.grand_total > 0 THEN ($collected + o.advance_verified) / o.grand_total ELSE 1 END";

        return DB::table('orders as o')
            ->joinSub($finals, 'f', 'f.order_id', '=', 'o.id')
            ->leftJoin('shipments as s', 's.id', '=', 'o.active_shipment_id')
            ->leftJoin('ad_cost_days as ad', fn ($j) => $j->on(DB::raw('DATE(o.created_at)'), '=', 'ad.day'))
            ->whereIn('o.status_id', $settled)
            ->when($channel, fn ($q) => $q->where('o.channel', $channel))
            ->selectRaw("o.id, o.order_no, o.channel, o.owner_id, f.final_at,
                CASE WHEN $isDelivered THEN 1 ELSE 0 END as delivered,
                CASE WHEN $isDelivered THEN $collected + o.advance_verified ELSE o.advance_verified END as revenue,
                CASE WHEN $isDelivered THEN $cogs * (CASE WHEN $share < 1 THEN $share ELSE 1 END) ELSE 0 END as cogs,
                COALESCE(s.delivery_charge, o.delivery_charge) as delivery,
                CASE WHEN $isDelivered THEN $collected * $pct ELSE 0 END as cod_fee,
                $packaging as packaging,
                CASE WHEN o.channel = 'b2b' THEN 0 ELSE COALESCE(ad.per_order, 0) END as ad_cost");
    }

    /** @return array{revenue: float, cogs: float, delivery: float, cod_fee: float, packaging: float, ad_cost: float, profit_before_ads: float, profit: float, orders: int, delivered: int} */
    public function totals(string $from, string $to, ?string $channel = null): array
    {
        $t = DB::query()->fromSub($this->orders($from, $to, $channel), 'x')
            ->selectRaw('COUNT(*) as orders, SUM(delivered) as delivered, SUM(revenue) as revenue, SUM(cogs) as cogs, SUM(delivery) as delivery, SUM(cod_fee) as cod_fee, SUM(packaging) as packaging, SUM(ad_cost) as ad_cost')
            ->first();
        $r = array_map(fn ($v) => (float) $v, (array) $t);
        $r['profit_before_ads'] = $r['revenue'] - $r['cogs'] - $r['delivery'] - $r['cod_fee'] - $r['packaging'];
        $r['profit'] = $r['profit_before_ads'] - $r['ad_cost'];
        $r['orders'] = (int) $r['orders'];
        $r['delivered'] = (int) $r['delivered'];

        return $r;
    }

    /** Grouped rows, paginated. Product/category rows are line level: revenue, cogs and gross profit only (no ads). */
    public function grouped(string $group, string $from, string $to, ?string $channel, int $perPage)
    {
        if (in_array($group, ['product', 'category'], true)) {
            $key = $group === 'product' ? 'p.id' : 'p.category_id';
            $label = $group === 'product' ? 'MAX(p.name)' : 'MAX(c.name)';

            return DB::query()->fromSub($this->orders($from, $to, $channel), 'x')
                ->join('order_items as i', 'i.order_id', '=', 'x.id')
                ->join('product_variants as v', 'v.id', '=', 'i.variant_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('x.delivered', 1)
                ->groupByRaw($key)
                ->selectRaw("$key as g, $label as label, COUNT(DISTINCT x.id) as orders, SUM(i.qty) as qty,
                    SUM(i.line_total) as revenue, SUM(i.qty * COALESCE(i.cost_price_snapshot, 0)) as cogs,
                    SUM(i.line_total) - SUM(i.qty * COALESCE(i.cost_price_snapshot, 0)) as profit")
                ->orderByDesc('revenue')->paginate($perPage)->withQueryString();
        }

        $key = match ($group) { 'channel' => 'x.channel', 'owner' => 'x.owner_id', default => 'DATE(x.final_at)' };

        return DB::query()->fromSub($this->orders($from, $to, $channel), 'x')
            ->groupByRaw($key)
            ->selectRaw("$key as g, COUNT(*) as orders, SUM(delivered) as delivered, SUM(revenue) as revenue, SUM(cogs) as cogs,
                SUM(delivery) as delivery, SUM(cod_fee) as cod_fee, SUM(packaging) as packaging, SUM(ad_cost) as ad_cost,
                SUM(revenue) - SUM(cogs) - SUM(delivery) - SUM(cod_fee) - SUM(packaging) - SUM(ad_cost) as profit")
            ->orderByRaw($group === 'day' ? 'g DESC' : 'revenue DESC')->paginate($perPage)->withQueryString();
    }
}
