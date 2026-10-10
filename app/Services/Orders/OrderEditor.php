<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changing items, prices or the delivery details of an existing order.
 * Never silent: every change is an order_amendments row (old vs new, reason,
 * person), a new append-only version, recomputed totals/COD and a readable
 * note. The status's edit_policy decides: free = apply now, approval = wait
 * for a manager, locked = refused (partial delivery or a new order instead).
 * Editing after packaging marks the order RED (repack) or relabel.
 */
class OrderEditor
{
    public function __construct(
        private OrderService $orders,
        private OrderCalculator $calculator,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array{items: list<array>, ship_name?: string, ship_phone?: string, ship_alt_phone?: ?string, ship_address?: string,
     *     ship_district?: ?string, ship_thana?: ?string, zone_id?: ?int, order_discount?: ?float}  $proposed
     * @return array{applied: bool, amendment_id: int}
     */
    public function request(Order $order, array $proposed, int $reasonId, User $by, int $expectedLock): array
    {
        return DB::transaction(function () use ($order, $proposed, $reasonId, $by, $expectedLock) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->lock_version !== $expectedLock) {
                throw ValidationException::withMessages(['order' => __('This order was changed by someone else. Reload and try again.')]);
            }
            if ($order->booking_claim && $order->booking_claimed_at && \Illuminate\Support\Carbon::parse($order->booking_claimed_at)->gt(now()->subMinutes(5))) {
                throw ValidationException::withMessages(['order' => __('The courier is being booked right now. Try again in a few seconds.')]);
            }
            $status = OrderStatus::map()[$order->status_id];
            if ($status['edit_policy'] === 'locked') {
                throw ValidationException::withMessages(['order' => __('A :s order cannot be edited. Use partial delivery or a new order.', ['s' => $status['name']])]);
            }

            $newItems = $this->items($order, $proposed['items'] ?? [], $by);
            $shipping = $this->shipping($order, $proposed);
            // The order-level discount is discount_total minus the line discounts. Not sent = keep it (it used to be lost on every edit).
            $currentDiscount = $this->orderDiscount($order);
            $orderDiscount = isset($proposed['order_discount']) ? round((float) $proposed['order_discount'], 2) : $currentDiscount;
            $changes = $this->diff($order, $newItems, $shipping);
            if (abs($orderDiscount - $currentDiscount) > 0.001) {
                $changes[] = ['type' => 'discount', 'item' => 'order', 'from' => $currentDiscount, 'to' => $orderDiscount];
            }
            if ($changes === []) {
                throw ValidationException::withMessages(['items' => __('Nothing was changed.')]);
            }

            $contentChanged = collect($changes)->contains(fn ($c) => in_array($c['type'], ['added', 'removed', 'qty', 'price'], true));
            // A website order keeps the delivery charge it was sold with, unless the delivery area changes.
            $keepSoldCharge = $order->channel === 'web' && (int) $shipping['zone_id'] === (int) $order->zone_id;
            $totals = $this->calculator->totals($newItems, $shipping['zone_id'], $orderDiscount,
                $keepSoldCharge ? (float) $order->delivery_charge : null);
            // A bigger discount than the limit needs a manager, same as when the order is created.
            $discountTooBig = $totals['discount_total'] > (float) settings('orders.discount_limit') && $totals['discount_total'] > (float) $order->discount_total + 0.001;
            $needsApproval = ($status['edit_policy'] === 'approval' || $discountTooBig) && ! $by->can('orders.approve');

            $id = DB::table('order_amendments')->insertGetId([
                'order_id' => $order->id,
                'from_version' => $order->current_version,
                'status_at_time_id' => $order->status_id,
                'reason_id' => $reasonId,
                'edit_class' => $contentChanged ? 'content' : 'label',
                'changes' => json_encode($changes),
                'proposed' => json_encode(['items' => $newItems, 'shipping' => $shipping, 'totals' => $totals]),
                'amount_diff' => round($totals['grand_total'] - (float) $order->grand_total, 2),
                'approval_status' => $needsApproval ? 'pending' : 'not_required',
                'requested_by' => $by->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($needsApproval) {
                $this->orders->note($order, 'amendment', __('Change requested, waiting for a manager: :c', ['c' => $this->describe($changes)]), $by, ['amendment_id' => $id]);
                $this->notifications->send('amendment_pending_approval', __('Approve a change on :no', ['no' => $order->order_no]), $this->describe($changes), [
                    'link' => route('orders.show', $order), 'subject' => ['order', $order->id],
                ]);

                return ['applied' => false, 'amendment_id' => $id];
            }

            $this->apply($order, $id, $by);

            return ['applied' => true, 'amendment_id' => $id];
        });
    }

    public function decide(Order $order, int $amendmentId, bool $approve, User $by): void
    {
        DB::transaction(function () use ($order, $amendmentId, $approve, $by) {
            $a = DB::table('order_amendments')->where('id', $amendmentId)->where('order_id', $order->id)->where('approval_status', 'pending')->lockForUpdate()->first();
            abort_unless($a, 404);
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $approve) {
                DB::table('order_amendments')->where('id', $a->id)->update(['approval_status' => 'rejected', 'approved_by' => $by->id, 'approved_at' => now(), 'updated_at' => now()]);
                $this->orders->note($order, 'amendment', __('Change rejected by :n.', ['n' => $by->name]), $by, ['amendment_id' => $a->id]);

                return;
            }
            // The order moved on since the request: the proposal is stale.
            if ($order->current_version !== (int) $a->from_version) {
                throw ValidationException::withMessages(['order' => __('The order changed after this request. Ask for the change again.')]);
            }
            DB::table('order_amendments')->where('id', $a->id)->update(['approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->apply($order, $a->id, $by);
        });
    }

    private function apply(Order $order, int $amendmentId, User $by): void
    {
        $a = DB::table('order_amendments')->where('id', $amendmentId)->first();
        $p = json_decode($a->proposed, true);
        $version = $order->current_version + 1;
        $oldCod = (float) $order->cod_amount;

        $frozen = DB::table('order_items')->where('order_id', $order->id)->whereNotNull('cost_price_snapshot')->pluck('cost_price_snapshot', 'variant_id');
        DB::table('order_items')->where('order_id', $order->id)->delete();
        $items = $p['items'];
        if ($order->confirmed_at) {
            $today = DB::table('product_variants')->whereIn('id', collect($items)->pluck('variant_id')->filter())->pluck('cost_price', 'id');
            $items = array_map(fn ($i) => $i + ['cost_price_snapshot' => $frozen[$i['variant_id'] ?? 0] ?? $today[$i['variant_id'] ?? 0] ?? null], $items);
        }
        $order->items()->createMany($items);
        $order->fill($p['shipping'] + $p['totals']);
        $order->current_version = $version;
        $order->lock_version++;

        // After packaging: content change = box is wrong (RED), address/phone only = new label (ORANGE).
        $wasPacked = $order->packed_version !== null;
        if ($wasPacked) {
            $order->edited_after_pack = true;
            if ($a->edit_class === 'label') {
                $order->packed_version = $version; // box still right; only the label must change
            }
        }
        $order->save();
        $this->calculator->applyPayments($order);

        $courierAction = 'none';
        if ($order->active_shipment_id) {
            // Only a real change of the cash to collect has to be updated at the courier (by hand); the label is re-issued either way.
            $courierAction = abs($oldCod - (float) $order->cod_amount) > 0.001 ? 'update_cod' : ($a->edit_class === 'label' ? 'reprint_label' : 'none');
        }
        DB::table('order_amendments')->where('id', $amendmentId)->update([
            'to_version' => $version, 'applied_at' => now(), 'courier_action' => $courierAction, 'updated_at' => now(),
        ]);
        $this->orders->snapshot($order, $version, $by->id);

        // The old label no longer scans: have the new one ready so the packer can print it without asking anyone.
        if ($order->active_shipment_id && in_array($order->status_id, OrderStatus::idsFor(['ready_for_packaging', 'packed', 'ready_for_pickup']), true)) {
            app(\App\Services\Courier\BookingService::class)->issueLabel($order, $order->active_shipment_id, null, __('Order edited (version :v)', ['v' => $version]));
        }

        $reason = DB::table('status_reasons')->where('id', $a->reason_id)->value('label_en');
        $diff = (float) $a->amount_diff;
        $this->orders->note($order, 'amendment', trim($this->describe(json_decode($a->changes, true))
            .($diff ? ', '.($diff > 0 ? '+' : '-').'৳'.number_format(abs($diff), 2) : '')
            .' · '.__('reason: :r', ['r' => $reason])
            .($a->approval_status === 'approved' ? ' · '.__('approved by :n', ['n' => $by->name]) : '')), $by, ['amendment_id' => $amendmentId, 'version' => $version]);

        app(\App\Services\Points\PointHooks::class)->amended($order, (int) $a->reason_id, $wasPacked);

        if ((float) $order->refund_due > 0) {
            $this->orders->note($order, 'payment', __('Advance is more than the new total: refund ৳:r due.', ['r' => $order->refund_due]), null);
        }
        if ($courierAction === 'update_cod') {
            $this->notifications->send('cod_update_needed', __('Update the COD of :no at the courier', ['no' => $order->order_no]),
                __('৳:a → ৳:b. Change it in the courier panel, then press "COD updated" on the order.', ['a' => number_format($oldCod), 'b' => number_format((float) $order->cod_amount)]), [
                    'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'priority' => 'urgent',
                    'user_ids' => array_values(array_unique(array_filter([$order->moderator_id, $by->id, ...app(\App\Services\Orders\DeskService::class)->managerIds()]))),
                ]);
        }
        if ($wasPacked && $a->edit_class === 'content') {
            $this->notifications->send('order_needs_repack', __(':no edited after packaging: repack', ['no' => $order->order_no]), $this->describe(json_decode($a->changes, true)), [
                'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'priority' => 'urgent',
            ]);
        }
    }

    /** New lines; unchanged lines keep the price they were sold at. */
    private function items(Order $order, array $rows, User $by): array
    {
        $old = $order->items()->get()->keyBy('variant_id');

        // Existing lines keep their sold price; new ones use the list price.
        $built = $this->orders->buildItems($rows, $by, false, $old->keys()->map(fn ($id) => (int) $id)->all());
        foreach ($built as &$item) {
            if (isset($old[$item['variant_id']]) && ! $by->can('orders.approve')) {
                $item['unit_price'] = (float) $old[$item['variant_id']]->unit_price;
                $item['line_total'] = round(max(0, $item['qty'] * $item['unit_price'] - $item['line_discount']), 2);
            }
        }

        return $built;
    }

    private function shipping(Order $order, array $p): array
    {
        $phone = isset($p['ship_phone']) ? Phone::normalize($p['ship_phone']) : $order->ship_phone;
        if (! $phone) {
            throw ValidationException::withMessages(['ship_phone' => __('Phone must be a Bangladesh mobile number like 01XXXXXXXXX.')]);
        }

        return [
            'ship_name' => trim($p['ship_name'] ?? $order->ship_name),
            'ship_phone' => $phone,
            'ship_alt_phone' => array_key_exists('ship_alt_phone', $p) ? Phone::normalize($p['ship_alt_phone']) : $order->ship_alt_phone,
            'ship_address' => trim($p['ship_address'] ?? $order->ship_address),
            'ship_district' => array_key_exists('ship_district', $p) ? ($p['ship_district'] ?: null) : $order->ship_district,
            'ship_thana' => array_key_exists('ship_thana', $p) ? ($p['ship_thana'] ?: null) : $order->ship_thana,
            'zone_id' => array_key_exists('zone_id', $p) ? ($p['zone_id'] ? (int) $p['zone_id'] : null) : $order->zone_id,
        ];
    }

    /** @return list<array{type: string, item?: string, from?: mixed, to?: mixed}> */
    private function diff(Order $order, array $newItems, array $shipping): array
    {
        $changes = [];
        $old = $order->items()->get()->keyBy('variant_id');
        $new = collect($newItems)->keyBy('variant_id');

        foreach ($new as $id => $n) {
            $o = $old[$id] ?? null;
            if (! $o) {
                $changes[] = ['type' => 'added', 'item' => $n['name_snapshot'], 'to' => (float) $n['qty']];
            } else {
                if ((float) $o->qty !== (float) $n['qty']) {
                    $changes[] = ['type' => 'qty', 'item' => $n['name_snapshot'], 'from' => (float) $o->qty, 'to' => (float) $n['qty']];
                }
                if ((float) $o->unit_price !== (float) $n['unit_price'] || (float) $o->line_discount !== (float) $n['line_discount']) {
                    $changes[] = ['type' => 'price', 'item' => $n['name_snapshot'], 'from' => (float) $o->line_total, 'to' => (float) $n['line_total']];
                }
            }
        }
        foreach ($old as $id => $o) {
            if (! isset($new[$id])) {
                $changes[] = ['type' => 'removed', 'item' => $o->name_snapshot, 'from' => (float) $o->qty];
            }
        }
        foreach ($shipping as $field => $value) {
            if ((string) $order->{$field} !== (string) $value) {
                $changes[] = ['type' => 'address', 'item' => $field, 'from' => $order->{$field}, 'to' => $value];
            }
        }

        return $changes;
    }

    /** The discount on the whole order (not on a line). */
    public function orderDiscount(Order $order): float
    {
        $lines = (float) DB::table('order_items')->where('order_id', $order->id)->sum('line_discount');

        return round(max(0, (float) $order->discount_total - $lines), 2);
    }

    private function describe(array $changes): string
    {
        return collect($changes)->map(fn ($c) => match ($c['type']) {
            'added' => __('added :i ×:q', ['i' => $c['item'], 'q' => $c['to'] + 0]),
            'removed' => __('removed :i', ['i' => $c['item']]),
            'qty' => __(':i ×:a → ×:b', ['i' => $c['item'], 'a' => $c['from'] + 0, 'b' => $c['to'] + 0]),
            'price' => __(':i price ৳:a → ৳:b', ['i' => $c['item'], 'a' => $c['from'], 'b' => $c['to']]),
            'address' => __(':f changed', ['f' => str_replace(['ship_', '_'], ['', ' '], $c['item'])]),
            'discount' => __('order discount ৳:a → ৳:b', ['a' => $c['from'] + 0, 'b' => $c['to'] + 0]),
        })->join(', ');
    }
}
