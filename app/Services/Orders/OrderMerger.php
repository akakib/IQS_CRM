<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Two open orders of one customer go out as one parcel: the items of the
 * order being merged are added to the other one (same edit as by hand, so
 * totals, COD and the timeline follow), and the merged order is cancelled as
 * a duplicate. Only before either is booked, and only when the merged order
 * has no payment of its own (money is never moved silently).
 */
class OrderMerger
{
    private const OPEN = ['new', 'record_verified', 'no_answer', 'hold', 'confirmed'];

    public function __construct(private OrderEditor $editor, private OrderStateMachine $machine, private OrderService $orders) {}

    /** Why these two cannot be merged, or null when they can. */
    public function refusal(Order $from, Order $into): ?string
    {
        $key = fn (Order $o) => OrderStatus::map()[$o->status_id]['key'];

        return match (true) {
            $from->id === $into->id => __('Choose another order.'),
            $from->customer_id !== $into->customer_id => __('Only orders of the same customer are merged.'),
            ! in_array($key($from), self::OPEN, true) || ! in_array($key($into), self::OPEN, true) => __('Both orders must still be open (not booked, packed or closed).'),
            (bool) $from->active_shipment_id || (bool) $into->active_shipment_id || $from->booking_state === 'queued' || $into->booking_state === 'queued' || $from->booking_claim || $into->booking_claim
                => __('One of them is booked with the courier already: edit that order instead.'),
            DB::table('order_payments')->where('order_id', $from->id)->exists() => __(':no has a payment: merge it the other way, into :no.', ['no' => $from->order_no]),
            default => null,
        };
    }

    public function merge(Order $from, Order $into, User $by): void
    {
        DB::transaction(function () use ($from, $into, $by) {
            $from = Order::whereKey($from->id)->lockForUpdate()->firstOrFail();
            $into = Order::whereKey($into->id)->lockForUpdate()->firstOrFail();
            if ($why = $this->refusal($from, $into)) {
                throw ValidationException::withMessages(['merge' => $why]);
            }

            // Every line of both orders; a product in both adds up (the kept order's line and price stay).
            $lines = [];
            foreach ([$into, $from] as $o) {
                foreach ($o->items()->get(['variant_id', 'qty', 'line_discount']) as $i) {
                    $lines[$i->variant_id] ??= ['variant_id' => (int) $i->variant_id, 'qty' => 0, 'line_discount' => (float) $i->line_discount];
                    $lines[$i->variant_id]['qty'] += (float) $i->qty;
                }
            }
            $reason = (int) DB::table('status_reasons')->where('reason_type', 'amendment')->where('system_key', 'customer_request')->value('id');
            $result = $this->editor->request($into, ['items' => array_values($lines)], $reason, $by, (int) $into->lock_version);
            if (! $result['applied']) {
                throw ValidationException::withMessages(['merge' => __(':no needs a manager to approve the change: ask a manager to merge it.', ['no' => $into->order_no])]);
            }

            $cancel = (int) DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'duplicate')->value('id');
            $this->machine->transition($from, 'cancelled', $by, 'system', $cancel, __('Merged into :no', ['no' => $into->order_no]));
            $this->orders->note($into->fresh(), 'system', __(':from merged into this order by :n: its items were added.', ['from' => $from->order_no, 'n' => $by->name]), $by);
        });
    }
}
