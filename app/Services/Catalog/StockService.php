<?php

namespace App\Services\Catalog;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Stock on the shelf, per variant, with a line for every change.
 *
 * Only counted variants move (stock_qty not null): count them once on the
 * Stock count page, and from then on packing takes away, a box put back or a
 * good item from a return brings back. An order's own lines are kept as
 * movements, so a repack after an edit moves only the difference.
 * At 0 a counted variant goes Out of stock by itself; back above 0 it returns
 * to In stock only if the count had put it out (a manual Out of stock stays).
 */
class StockService
{
    public const REASONS = [
        'count' => 'Counted', 'packed' => 'Packed', 'unpacked' => 'Box put back', 'return_good' => 'Return, good',
        'damaged' => 'Return, damaged', 'adjust' => 'Adjusted',
    ];

    public function __construct(private ProductService $products) {}

    /** A count on the shelf: the number is set, the difference is recorded. */
    public function count(int $variantId, int $qty, ?User $by, ?string $note = null): void
    {
        DB::transaction(function () use ($variantId, $qty, $by, $note) {
            $v = DB::table('product_variants')->where('id', $variantId)->lockForUpdate()->first(['id', 'stock_qty']);
            abort_unless($v, 404);
            $change = $qty - (int) ($v->stock_qty ?? 0);
            DB::table('product_variants')->where('id', $variantId)->update(['stock_qty' => $qty, 'stock_counted_at' => now()]);
            DB::table('stock_movements')->insert([
                'variant_id' => $variantId, 'qty_change' => $change, 'qty_after' => $qty, 'reason' => 'count',
                'user_id' => $by?->id, 'note' => $note, 'created_at' => now(),
            ]);
            $this->followAvailability($variantId, $qty, $by);
        });
    }

    /**
     * Make what this order has taken from the shelf equal $want (variant => qty):
     * packed = its items, put back = nothing. Only the difference moves.
     *
     * @param  array<int, int>  $want
     */
    public function settleOrder(Order $order, array $want, string $reason, ?User $by): void
    {
        DB::transaction(function () use ($order, $want, $reason, $by) {
            $taken = DB::table('stock_movements')->where('order_id', $order->id)->whereIn('reason', ['packed', 'unpacked'])
                ->groupBy('variant_id')->selectRaw('variant_id, -SUM(qty_change) as n')->pluck('n', 'variant_id')->map(fn ($n) => (int) $n);
            $ids = collect(array_keys($want))->merge($taken->keys())->unique()->all();
            $counted = DB::table('product_variants')->whereIn('id', $ids)->whereNotNull('stock_qty')->lockForUpdate()->get(['id', 'stock_qty'])->keyBy('id');
            foreach ($counted as $id => $v) {
                $change = (int) ($taken[$id] ?? 0) - (int) ($want[$id] ?? 0); // taking more = negative
                if ($change === 0) {
                    continue;
                }
                $after = (int) $v->stock_qty + $change;
                DB::table('product_variants')->where('id', $id)->update(['stock_qty' => $after]);
                DB::table('stock_movements')->insert([
                    'variant_id' => $id, 'qty_change' => $change, 'qty_after' => $after, 'reason' => $change < 0 ? 'packed' : $reason,
                    'order_id' => $order->id, 'user_id' => $by?->id, 'created_at' => now(),
                ]);
                $this->followAvailability($id, $after, $by);
            }
        });
    }

    /** A return received: good items back on the shelf. @param array<int, int> $good variant => qty */
    public function returnGood(Order $order, array $good, ?User $by): void
    {
        $counted = DB::table('product_variants')->whereIn('id', array_keys($good))->whereNotNull('stock_qty')->lockForUpdate()->get(['id', 'stock_qty']);
        foreach ($counted as $v) {
            $add = (int) $good[$v->id];
            if ($add <= 0) {
                continue;
            }
            $after = (int) $v->stock_qty + $add;
            DB::table('product_variants')->where('id', $v->id)->update(['stock_qty' => $after]);
            DB::table('stock_movements')->insert([
                'variant_id' => $v->id, 'qty_change' => $add, 'qty_after' => $after, 'reason' => 'return_good',
                'order_id' => $order->id, 'user_id' => $by?->id, 'created_at' => now(),
            ]);
            $this->followAvailability($v->id, $after, $by);
        }
    }

    /** What the order's items are now, as variant => qty. */
    public function itemsOf(Order $order): array
    {
        return DB::table('order_items')->where('order_id', $order->id)->whereNotNull('variant_id')
            ->groupBy('variant_id')->selectRaw('variant_id, SUM(qty) as n')->pluck('n', 'variant_id')
            ->map(fn ($n) => (int) round((float) $n))->all();
    }

    private function followAvailability(int $variantId, int $qty, ?User $by): void
    {
        $v = DB::table('product_variants')->where('id', $variantId)->first(['availability_status', 'availability_source']);
        if ($qty <= 0 && $v->availability_status === 'in_stock') {
            $this->products->setAvailability([$variantId], 'out_of_stock', $by?->id, null, __('Stock count reached 0'), 'stock');
        } elseif ($qty > 0 && $v->availability_status === 'out_of_stock' && $v->availability_source === 'stock') {
            $this->products->setAvailability([$variantId], 'in_stock', $by?->id, null, __('Back in stock: :n on the shelf', ['n' => $qty]), 'stock');
        }
    }
}
