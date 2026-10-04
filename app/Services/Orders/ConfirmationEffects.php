<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * What happens the moment an order becomes Confirmed:
 * - cost price is frozen on each line (for order P&L),
 * - item availability decides the path: all in stock -> stays Confirmed for
 *   booking; any Pre-order item -> Hold (stock arriving); any out-of-stock
 *   item -> Hold (stock out). Pre-order quantities are counted against the
 *   variant's limit, which flips it to Out of stock when reached.
 */
class ConfirmationEffects
{
    public function __construct(private OrderStateMachine $machine) {}

    public function handle(Order $order, array $from, array $to): void
    {
        if ($to['key'] !== 'confirmed') {
            return;
        }

        DB::statement('UPDATE order_items SET cost_price_snapshot = (SELECT cost_price FROM product_variants WHERE product_variants.id = order_items.variant_id) WHERE order_id = ?', [$order->id]);

        $items = DB::table('order_items as i')->join('product_variants as v', 'v.id', '=', 'i.variant_id')
            ->where('i.order_id', $order->id)->get(['i.variant_id', 'i.qty', 'i.name_snapshot', 'v.availability_status', 'v.backorder_limit_qty', 'v.backorder_taken_qty']);

        $out = $items->where('availability_status', 'out_of_stock');
        $pre = $items->where('availability_status', 'backorder');

        if ($out->isNotEmpty()) {
            $this->hold($order, 'stock_out', __('Out of stock: :i', ['i' => $out->pluck('name_snapshot')->join(', ')]));

            return;
        }

        if ($pre->isNotEmpty()) {
            foreach ($pre as $line) {
                $taken = (float) $line->backorder_taken_qty + (float) $line->qty;
                $update = ['backorder_taken_qty' => $taken];
                if ($line->backorder_limit_qty !== null && $taken >= (float) $line->backorder_limit_qty) {
                    $update['availability_status'] = 'out_of_stock';
                    $update['availability_source'] = 'auto';
                    DB::table('availability_events')->insert([
                        'variant_id' => $line->variant_id, 'from_status' => 'backorder', 'to_status' => 'out_of_stock',
                        'source' => 'auto', 'note' => 'Pre-order limit reached', 'created_at' => now(),
                    ]);
                    app(\App\Services\Catalog\ChannelSync::class)->queue($line->variant_id, ['stock_status']);
                }
                ProductVariant::whereKey($line->variant_id)->update($update);
            }
            $expected = ProductVariant::whereIn('id', $pre->pluck('variant_id'))->max('expected_restock_date');
            $this->hold($order, 'awaiting_stock', __('Pre-order: :i', ['i' => $pre->pluck('name_snapshot')->join(', ')]));
            if ($expected) {
                $order->forceFill(['hold_expected_date' => $expected])->save();
            }
        }
    }

    private function hold(Order $order, string $reasonKey, string $note): void
    {
        $reason = DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', $reasonKey)->value('id');
        $this->machine->transition($order, 'hold', null, 'system', $reason, $note);
    }
}
