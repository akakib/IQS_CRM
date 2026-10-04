<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * When a variant's availability changes:
 * - Out of stock / Pre-order: open, unpacked orders containing it move to
 *   Hold (stock out / stock arriving); the owner gets a task. A booked order
 *   also gets a "cancel the booking" note for the courier desk.
 * - Back in stock: orders waiting on Hold "stock arriving" whose items are
 *   all sellable again are released to Confirmed, oldest first.
 */
class AvailabilityEffects
{
    private const OPEN = ['new', 'record_verified', 'no_answer', 'confirmed', 'ready_for_packaging'];

    public function __construct(private OrderStateMachine $machine, private OrderService $orders, private NotificationService $notifications) {}

    public function changed(int $variantId, string $from, string $to): void
    {
        if ($to === 'in_stock') {
            $this->release($variantId);

            return;
        }

        $reasonKey = $to === 'backorder' ? 'awaiting_stock' : 'stock_out';
        $reasonId = DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', $reasonKey)->value('id');
        $orders = Order::whereIn('status_id', OrderStatus::idsFor(self::OPEN))
            ->whereIn('id', DB::table('order_items')->select('order_id')->where('variant_id', $variantId))
            ->orderBy('id')->get();

        foreach ($orders as $order) {
            $wasBooked = OrderStatus::map()[$order->status_id]['key'] === 'ready_for_packaging';
            $this->machine->transition($order, 'hold', null, 'system', $reasonId, $to === 'backorder' ? __('Item became pre-order') : __('Item went out of stock'));
            if ($wasBooked) {
                $this->orders->note($order, 'courier', __('Booked parcel is on hold: cancel or keep the Steadfast booking.'), null);
            }
            $this->notifications->send('order_held_for_stock', __(':no on hold: offer a substitute or wait', ['no' => $order->order_no]), null, [
                'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'user_ids' => array_filter([$order->owner_id]),
            ]);
        }
    }

    private function release(int $variantId): void
    {
        $awaiting = DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'awaiting_stock')->value('id');
        $orders = Order::where('status_id', OrderStatus::idFor('hold'))->where('hold_reason_id', $awaiting)
            ->whereIn('id', DB::table('order_items')->select('order_id')->where('variant_id', $variantId))
            ->orderBy('id')->get();

        $released = [];
        foreach ($orders as $order) {
            $blocked = DB::table('order_items as i')->join('product_variants as v', 'v.id', '=', 'i.variant_id')
                ->where('i.order_id', $order->id)->whereIn('v.availability_status', ['out_of_stock', 'backorder'])->exists();
            if ($blocked) {
                continue;
            }
            $this->machine->transition($order, 'confirmed', null, 'system', null, __('Stock arrived: released (first come, first served).'));
            $released[] = $order->id;
        }

        if ($released) {
            DB::table('restock_releases')->insert([
                'variant_id' => $variantId, 'qty_arrived' => null, 'released_order_ids' => json_encode($released),
                'remaining_waiting_qty' => null, 'user_id' => auth()->id(), 'created_at' => now(),
            ]);
        }
    }
}
