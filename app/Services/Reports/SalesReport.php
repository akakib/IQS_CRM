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

    /**
     * Orders at one step in the dates, each once; for one person: packed by the packer, confirmed by whoever confirmed
     * it, delivered by the order's moderator. The dashboard tiles and the Telegram summaries read this, so they
     * always agree with this report.
     */
    public function count(string $stage, string $from, string $to, ?int $userId = null): int
    {
        $col = ['packed' => 'packer_id', 'delivered' => 'moderator_id'][$stage] ?? 'confirmed_by';

        return DB::query()->fromSub($this->stage($stage, $from, $to), 's')
            ->when($userId, fn ($q) => $q->whereIn('s.order_id', DB::table('orders')->select('id')->where($col, $userId)))->count();
    }

    /**
     * Orders, money, grams and ml at each step. A step counts what happened in the dates (an order confirmed
     * today stays in today's Confirmed), so what became of them since is told beside it: now cancelled,
     * on hold, or returned. An order held and confirmed again counts once (its first confirm).
     */
    public function summary(string $from, string $to): array
    {
        $now = fn (string $key) => 'COALESCE(SUM(CASE WHEN o.status_id IN ('.implode(',', array_map('intval', OrderStatus::idsFor([$key]) ?: [0])).') THEN 1 ELSE 0 END), 0)';
        $out = [];
        foreach (array_keys(self::STAGES) as $stage) {
            $orders = DB::table('orders as o')->joinSub($this->stage($stage, $from, $to), 's', 's.order_id', '=', 'o.id')
                ->selectRaw('COUNT(*) as orders, COALESCE(SUM(o.grand_total), 0) as money, '.$now('cancelled').' as cancelled, '.$now('hold').' as hold, '.$now('returned').' as returned')
                ->first();
            $amount = $this->items($stage, $from, $to)->selectRaw($this->gramsSql().' as grams, '.$this->mlSql().' as ml')->first();
            $out[$stage] = ['orders' => (int) $orders->orders, 'money' => (float) $orders->money, 'cancelled' => (int) $orders->cancelled, 'hold' => (int) $orders->hold, 'returned' => (int) $orders->returned,
                'grams' => (float) ($amount->grams ?? 0), 'ml' => (float) ($amount->ml ?? 0)];
        }

        return $out;
    }

    /** One row per item sold at this step: how many, how much (g / ml) and the money of those lines. Most money first. */
    public function products(string $stage, string $from, string $to): Collection
    {
        return $this->items($stage, $from, $to)->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->groupBy('v.id', 'p.id', 'p.name', 'v.name', 'v.sku', 'v.unit', 'v.size_qty', 'v.size_unit', 'v.availability_status', 'c.name')
            ->selectRaw('v.id, p.id as product_id, p.name as product, v.name as variant, v.sku, v.unit, v.size_qty, v.size_unit, v.availability_status as stock, c.name as category, SUM(i.qty) as qty, '.$this->gramsSql().' as grams, '.$this->mlSql().' as ml, SUM(i.line_total) as money')
            ->orderByDesc('money')->get();
    }

    /**
     * The sold items (from products()) by category, then by item with its sizes: Dates 38.5 kg > Ajwa Large
     * 12.5 kg > 3kg ×3, 1kg ×2. Grams and ml are added up apart (never mixed); an item with no size counts in
     * pieces. Most money first at each level. No query: it reshapes what products() returned.
     */
    public function categories(Collection $products): Collection
    {
        $sum = fn (Collection $rows) => ['money' => (float) $rows->sum('money'), 'grams' => (float) $rows->sum('grams'), 'ml' => (float) $rows->sum('ml'),
            'pcs' => (float) $rows->filter(fn ($r) => (float) $r->grams + (float) $r->ml == 0)->sum('qty')];

        return $products->groupBy(fn ($r) => $r->category ?: __('No category'))->map(fn ($rows, $cat) => ['name' => $cat] + $sum($rows) + [
            'items' => $rows->groupBy('product_id')->map(fn ($v) => ['name' => $v->first()->product, 'product_id' => $v->first()->product_id] + $sum($v) + [
                'sizes' => $v->sortByDesc('money')->map(fn ($r) => [
                    'label' => $r->variant !== 'Default' ? $r->variant : (in_array($r->size_unit, ['g', 'ml'], true) ? \App\Support\Units::amount($r->size_unit === 'g' ? (float) $r->size_qty : 0, $r->size_unit === 'ml' ? (float) $r->size_qty : 0) : null),
                    'qty' => \App\Support\Units::qty($r->qty, $r->unit),
                    'unsized' => $r->size_unit === null && ! \App\Support\Units::measured($r->unit),
                ])->values()->all(),
            ])->sortByDesc('money')->values()->all(),
        ])->sortByDesc('money')->values();
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

    /**
     * Where the orders at this step came from: per channel, how many and the money, and how many of
     * them were from returning customers (an earlier order of theirs was delivered). One grouped query.
     */
    public function channels(string $stage, string $from, string $to): Collection
    {
        $returning = $this->returningSql();

        return DB::table('orders as o')->joinSub($this->stage($stage, $from, $to), 's', 's.order_id', '=', 'o.id')
            ->groupBy('o.channel')
            ->selectRaw("o.channel, COUNT(*) as orders, COALESCE(SUM(o.grand_total), 0) as money,
                SUM(CASE WHEN $returning THEN 1 ELSE 0 END) as ret, COALESCE(SUM(CASE WHEN $returning THEN o.grand_total ELSE 0 END), 0) as ret_money")
            ->orderByDesc('orders')->get();
    }

    /**
     * The orders behind a number on the page, for its popup: one day (or month), one item, or one channel
     * at this step. At most 200, oldest first; with how many there are and how many came back (returned).
     *
     * @param  array{day?: string, item?: int, product?: int, channel?: string}  $only
     */
    public function orders(string $stage, string $from, string $to, array $only): array
    {
        $returned = implode(',', array_map('intval', OrderStatus::idsFor(['returned']) ?: [0]));
        $q = DB::table('orders as o')->joinSub($this->stage($stage, $from, $to), 's', 's.order_id', '=', 'o.id')
            ->when($only['day'] ?? null, fn ($q, $d) => $q->whereRaw('substr(s.at, 1, '.strlen($d).') = ?', [$d]))
            ->when($only['channel'] ?? null, fn ($q, $c) => $q->where('o.channel', $c))
            ->when($only['item'] ?? null, fn ($q, $v) => $q->whereExists(fn ($e) => $e->from('order_items as x')->whereColumn('x.order_id', 'o.id')->where('x.variant_id', $v)))
            ->when($only['product'] ?? null, fn ($q, $p) => $q->whereExists(fn ($e) => $e->from('order_items as x')->join('product_variants as xv', 'xv.id', '=', 'x.variant_id')->whereColumn('x.order_id', 'o.id')->where('xv.product_id', $p)));
        $count = (clone $q)->selectRaw("COUNT(*) as n, COALESCE(SUM(CASE WHEN o.status_id IN ($returned) THEN 1 ELSE 0 END), 0) as returned")->first();
        $item = (int) ($only['item'] ?? 0);
        $rows = $q->join('customers as cu', 'cu.id', '=', 'o.customer_id')->leftJoin('users as u', 'u.id', '=', 'o.moderator_id')
            ->selectRaw('o.id, o.order_no, o.channel, o.status_id, o.grand_total, s.at, cu.name as customer, u.name as moderator, CASE WHEN '.$this->returningSql().' THEN 1 ELSE 0 END as is_returning,
                (SELECT COUNT(*) FROM order_items x WHERE x.order_id = o.id) as line_count,
                (SELECT SUM(x.qty) FROM order_items x WHERE x.order_id = o.id AND x.variant_id = ?) as item_qty', [$item])
            ->orderBy('s.at')->limit(200)->get();
        $first = DB::table('order_items as x')->join('product_variants as v', 'v.id', '=', 'x.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('x.order_id', $rows->pluck('id'))->orderBy('x.id')->get(['x.order_id', 'p.name'])->unique('order_id')->pluck('name', 'order_id');

        return ['rows' => $rows->each(fn ($r) => $r->first_item = $first[$r->id] ?? null), 'count' => (int) $count->n, 'returned' => (int) $count->returned];
    }

    /** An earlier order of the same customer was delivered (fully or partly). */
    private function returningSql(): string
    {
        $ids = implode(',', array_map('intval', OrderStatus::idsFor(['delivered', 'partial_delivered']) ?: [0]));

        return "EXISTS (SELECT 1 FROM orders o2 WHERE o2.customer_id = o.customer_id AND o2.id < o.id AND o2.status_id IN ($ids))";
    }

    /** The order lines of the orders at this step, with their item. */
    private function items(string $stage, string $from, string $to): Builder
    {
        return DB::table('order_items as i')->joinSub($this->stage($stage, $from, $to), 's', 's.order_id', '=', 'i.order_id')
            ->join('product_variants as v', 'v.id', '=', 'i.variant_id');
    }

    /** Grams of a line: sold by weight (g or kg) as is, else pieces times the item's size in grams. */
    private function gramsSql(): string
    {
        return $this->amountSql('g');
    }

    private function mlSql(): string
    {
        return $this->amountSql('ml');
    }

    /** The CASE for one measure (g or ml) from Units::GRAMS_PER: a line sold in the other measure counts 0 here. */
    private function amountSql(string $small): string
    {
        $big = $small === 'g' ? 'kg' : 'l';
        $other = $small === 'g' ? "'ml', 'l'" : "'g', 'kg'";
        $per = \App\Support\Units::GRAMS_PER[$big];

        return "COALESCE(SUM(CASE WHEN i.unit = '{$small}' THEN i.qty WHEN i.unit = '{$big}' THEN i.qty * {$per} WHEN i.unit IN ({$other}) THEN 0"
            ." WHEN v.size_unit = '{$small}' THEN i.qty * v.size_qty ELSE 0 END), 0)";
    }
}
