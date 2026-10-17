<?php

namespace App\Services\Reports;

use App\Models\OrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales over a date range, at three steps, each on its own day: Confirmed (the day it was confirmed),
 * Packed (the day it was packed) and Delivered (the day the courier delivered it, partly too).
 * Orders, money (the order totals) and how much went out: grams and ml, from each item's size
 * (product_variants.size_qty / size_unit, read from the names) times how many. A dozen grouped queries.
 */
class SalesReport
{
    public const STAGES = ['confirmed' => 'Confirmed', 'packed' => 'Packed', 'delivered' => 'Delivered'];

    /** @return array{order_id: int, at: string} rows of the orders that reached this step in the range (once each) */
    public function stage(string $stage, string $from, string $to): Builder
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $event = fn (array $keys) => DB::table('order_events')->whereIn('to_status_id', OrderStatus::idsFor($keys))->whereBetween('created_at', $range)
            ->groupBy('order_id')->selectRaw('order_id, MIN(created_at) as at');

        return match ($stage) {
            'packed' => DB::table('orders')->whereBetween('packed_at', $range)->selectRaw('id as order_id, packed_at as at'),
            'delivered' => $event(['delivered', 'partial_delivered']),
            default => $event(['confirmed']),
        };
    }

    /** Orders, money, grams and ml at each step; and how many of them were cancelled or returned since. */
    public function summary(string $from, string $to): array
    {
        $gone = OrderStatus::idsFor(['cancelled', 'returned']);
        $out = [];
        foreach (array_keys(self::STAGES) as $stage) {
            $orders = DB::table('orders as o')->joinSub($this->stage($stage, $from, $to), 's', 's.order_id', '=', 'o.id')
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM(o.grand_total), 0) as money, COALESCE(SUM(CASE WHEN o.status_id IN ('.implode(',', $gone ?: [0]).') THEN 1 ELSE 0 END), 0) as gone')
                ->first();
            $amount = $this->items($stage, $from, $to)->selectRaw($this->gramsSql().' as grams, '.$this->mlSql().' as ml')->first();
            $out[$stage] = ['orders' => (int) $orders->orders, 'money' => (float) $orders->money, 'gone' => (int) $orders->gone,
                'grams' => (float) ($amount->grams ?? 0), 'ml' => (float) ($amount->ml ?? 0)];
        }

        return $out;
    }

    /** One row per item sold at this step: how many, how much (g / ml) and the money of those lines. Most money first. */
    public function products(string $stage, string $from, string $to): Collection
    {
        return $this->items($stage, $from, $to)->join('products as p', 'p.id', '=', 'v.product_id')
            ->groupBy('v.id', 'p.name', 'v.name', 'v.sku')
            ->selectRaw('v.id, p.name as product, v.name as variant, v.sku, SUM(i.qty) as qty, '.$this->gramsSql().' as grams, '.$this->mlSql().' as ml, SUM(i.line_total) as money')
            ->orderByDesc('money')->get();
    }

    /** By day or by month: orders, money, grams and ml at this step. Newest first. */
    public function periods(string $stage, string $from, string $to, string $by): Collection
    {
        $key = $by === 'month' ? 'substr(s.at, 1, 7)' : 'substr(s.at, 1, 10)';
        $orders = DB::table('orders as o')->joinSub($this->stage($stage, $from, $to), 's', 's.order_id', '=', 'o.id')
            ->groupByRaw($key)->selectRaw($key.' as period, COUNT(*) as orders, SUM(o.grand_total) as money')->get()->keyBy('period');
        $amount = $this->items($stage, $from, $to)->groupByRaw($key)->selectRaw($key.' as period, '.$this->gramsSql().' as grams, '.$this->mlSql().' as ml')->get()->keyBy('period');

        return $orders->map(fn ($r, $p) => (object) ['period' => $p, 'orders' => (int) $r->orders, 'money' => (float) $r->money,
            'grams' => (float) ($amount[$p]->grams ?? 0), 'ml' => (float) ($amount[$p]->ml ?? 0)])->sortKeysDesc()->values();
    }

    /** The order lines of the orders at this step, with their item. */
    private function items(string $stage, string $from, string $to): Builder
    {
        return DB::table('order_items as i')->joinSub($this->stage($stage, $from, $to), 's', 's.order_id', '=', 'i.order_id')
            ->join('product_variants as v', 'v.id', '=', 'i.variant_id');
    }

    /** Grams of a line: sold by weight (g) as is, else pieces times the item's size in grams. */
    private function gramsSql(): string
    {
        return "COALESCE(SUM(CASE WHEN i.unit = 'g' THEN i.qty WHEN v.size_unit = 'g' THEN i.qty * v.size_qty ELSE 0 END), 0)";
    }

    private function mlSql(): string
    {
        return "COALESCE(SUM(CASE WHEN i.unit <> 'g' AND v.size_unit = 'ml' THEN i.qty * v.size_qty ELSE 0 END), 0)";
    }
}
