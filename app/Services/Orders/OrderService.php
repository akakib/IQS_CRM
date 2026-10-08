<?php

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Services\NotificationService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creating orders, reassigning, notes and payments. Taking orders lives in DeskService. */
class OrderService
{
    public function __construct(
        private OrderCalculator $calculator,
        private CustomerService $customers,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array{channel: string, phone: string, name: string, customer_address_id?: ?int, address_line?: ?string,
     *     district?: ?string, thana?: ?string, zone_id?: ?int, items: list<array{variant_id: int, qty: float, unit_price?: ?float, line_discount?: ?float}>,
     *     order_discount?: ?float, customer_note?: ?string, external_ref?: ?string, utm?: ?array, fbp?: ?string, fbc?: ?string,
     *     delivery_charge?: ?float, advance?: ?array, alt_phone?: ?string}  $data
     * @param  string  $source  user | webhook
     */
    public function create(array $data, ?User $by, string $source = 'user'): Order
    {
        $phone = Phone::normalize($data['phone'] ?? null)
            ?? throw ValidationException::withMessages(['phone' => __('Phone must be a Bangladesh mobile number like 01XXXXXXXXX.')]);

        return DB::transaction(function () use ($data, $by, $source, $phone) {
            $customer = $this->customers->findByPhone($phone)
                ?? $this->customers->save(new Customer, ['name' => $data['name'] ?: $phone, 'primary_phone' => $phone], [], [], $by?->id);

            $address = ! empty($data['customer_address_id'])
                ? $customer->addresses()->whereKey($data['customer_address_id'])->first()
                : null;
            if (! $address) {
                if (blank($data['address_line'] ?? null)) {
                    throw ValidationException::withMessages(['address_line' => __('Delivery address is required.')]);
                }
                $address = $customer->addresses()->create([
                    'address_line' => trim($data['address_line']),
                    'district' => $data['district'] ?? null,
                    'thana' => $data['thana'] ?? null,
                    'zone_id' => $data['zone_id'] ?? null,
                    'is_default' => ! $customer->addresses()->exists(),
                ]);
            }

            $items = $this->buildItems($data['items'] ?? [], $by, $source === 'webhook');
            $totals = $this->calculator->totals($items, $address->zone_id ?? ($data['zone_id'] ?? null), (float) ($data['order_discount'] ?? 0),
                isset($data['delivery_charge']) ? (float) $data['delivery_charge'] : null);

            if ($source === 'user' && $totals['discount_total'] > (float) settings('orders.discount_limit') && ! $by?->can('orders.approve')) {
                throw ValidationException::withMessages(['order_discount' => __('Discounts above ৳:n need a manager. Ask a manager to create or approve this order.', ['n' => settings('orders.discount_limit')])]);
            }

            $isChat = $data['channel'] !== 'web';
            $duplicate = DB::table('orders')->where('customer_id', $customer->id)
                ->whereNotIn('status_id', OrderStatus::idsFor(['cancelled', 'delivered', 'returned', 'partial_delivered']))
                ->where('created_at', '>=', now()->subHours((int) settings('orders.duplicate_window_hours')))->exists();

            $order = Order::create([
                'channel' => $data['channel'],
                'chat_channel_id' => $data['chat_channel_id'] ?? null,
                'external_ref' => $data['external_ref'] ?? null,
                'customer_id' => $customer->id,
                'status_id' => OrderStatus::idFor('new'),
                'moderator_id' => $isChat ? $by?->id : null,
                'assigned_at' => $isChat && $by ? now() : null,
                'queue_since' => $isChat && $by ? null : now(),
                'created_by' => $by?->id,
                'ship_name' => trim($data['name'] ?: $customer->name),
                'ship_phone' => $phone,
                'ship_alt_phone' => Phone::normalize($data['alt_phone'] ?? null),
                'ship_address' => $address->address_line,
                'ship_district' => $address->district,
                'ship_thana' => $address->thana,
                'zone_id' => $address->zone_id,
                'customer_note' => $data['customer_note'] ?? null,
                'utm' => $data['utm'] ?? null,
                'fbp' => $data['fbp'] ?? null,
                'fbc' => $data['fbc'] ?? null,
                'source_campaign' => $data['source_campaign'] ?? null,
                'is_duplicate_flag' => $duplicate,
            ] + $totals + ['cod_amount' => $totals['grand_total']]);

            $order->forceFill(['order_no' => 'IQ'.(10000 + $order->id)])->save();
            $order->items()->createMany($items);
            $this->snapshot($order, 1, $by?->id);

            DB::table('order_events')->insert([
                'order_id' => $order->id, 'from_status_id' => null, 'to_status_id' => $order->status_id,
                'source' => $source === 'webhook' ? 'webhook' : 'user', 'user_id' => $by?->id, 'created_at' => now(),
            ]);
            $this->note($order, 'system', __('Order created via :ch:by.', ['ch' => $data['channel'], 'by' => $by ? ' '.__('by :n', ['n' => $by->name]) : '']), $by);
            if ($duplicate) {
                $this->note($order, 'system', __('Same customer already has an open order in the last :h hours. Check for a duplicate.', ['h' => settings('orders.duplicate_window_hours')]), null);
            }

            if ($isChat && $by) {
                $this->openAssignment($order, $by->id, 'created', null);
            }

            DB::table('customers')->where('id', $customer->id)->update([
                'orders_count' => DB::raw('orders_count + 1'),
                'first_order_at' => $customer->first_order_at ?? now(),
            ]);

            if (! empty($data['advance']['amount'])) {
                $this->addPayment($order, $data['advance'] + ['payment_type' => 'advance'], $by);
            }

            app(\App\Services\Tracking\TrackingService::class)->handle($order, 'order_created');

            if (! $order->moderator_id) {
                $this->notifications->send('new_order', __('New order :no · ৳:t', ['no' => $order->order_no, 't' => number_format((float) $order->grand_total)]),
                    $order->ship_name, ['link' => route('desk.notice', $order), 'subject' => ['order', $order->id], 'group_key' => 'new_order',
                        'except_user_ids' => app(DeskService::class)->notFreeForNewOrders()]);
            }

            return $order->refresh();
        });
    }

    /** Admin only: move the order to another moderator, with a reason (it can count against the previous one). */
    public function reassign(Order $order, User $by, User $to, int $reasonId): Order
    {
        return DB::transaction(function () use ($order, $by, $to, $reasonId) {
            $previous = $order->moderator_id;
            DB::table('order_assignments')->where('order_id', $order->id)->whereNull('ended_at')->update(['ended_at' => now(), 'ended_reason' => 'reassigned']);
            app(DeskService::class)->closeWorkLog($order->id, 'reassigned');
            $order->forceFill(['moderator_id' => $to->id, 'assigned_at' => now(), 'queue_since' => null, 'action_due_at' => null, 'timer_overran_at' => null,
                'lock_version' => $order->lock_version + 1])->save();
            $this->openAssignment($order, $to->id, 'reassigned', $by->id, $reasonId);

            $reason = DB::table('status_reasons')->where('id', $reasonId)->value('label_en');
            $from = $previous ? DB::table('users')->where('id', $previous)->value('name') : __('nobody');
            $this->note($order, 'assignment', __('Reassigned from :a to :b · :r', ['a' => $from, 'b' => $to->name, 'r' => $reason]), $by,
                ['previous_moderator_id' => $previous, 'reason_id' => $reasonId]);
            $order->refresh();
            app(\App\Services\Points\PointHooks::class)->reassigned($order, $previous, $reasonId);

            // No timer starts here: it starts when the new moderator opens the order.

            return $order;
        });
    }

    /**
     * The COD changed after booking and the courier still has the old amount.
     *
     * @return array{courier: string, cn: ?string, booked: float, now: float}|null
     */
    public function pendingCodUpdate(Order $order): ?array
    {
        if (! DB::table('order_amendments')->where('order_id', $order->id)->where('courier_action', 'update_cod')->whereNull('courier_action_done_at')->exists()) {
            return null;
        }
        $shipment = DB::table('shipments')->where('id', $order->active_shipment_id)->first(['courier', 'consignment_id', 'cod_amount']);

        return [
            'courier' => ucfirst((string) ($shipment->courier ?? 'courier')),
            'cn' => $shipment->consignment_id ?? null,
            'booked' => (float) ($shipment->cod_amount ?? 0),
            'now' => (float) $order->cod_amount,
        ];
    }

    /** Someone changed the COD in the courier's panel by hand: unblock the handover and keep a record. */
    public function markCodUpdated(Order $order, User $by): void
    {
        $pending = $this->pendingCodUpdate($order);
        if (! $pending) {
            return;
        }
        DB::table('order_amendments')->where('order_id', $order->id)->where('courier_action', 'update_cod')->whereNull('courier_action_done_at')
            ->update(['courier_action_done_at' => now(), 'updated_at' => now()]);
        DB::table('shipments')->where('id', $order->active_shipment_id)->update(['cod_amount' => $order->cod_amount, 'updated_at' => now()]);
        $this->note($order, 'courier', __('COD updated at :c by hand: ৳:a → ৳:b (confirmed by :n).', [
            'c' => $pending['courier'], 'a' => number_format($pending['booked']), 'b' => number_format($pending['now']), 'n' => $by->name,
        ]), $by);
    }

    /**
     * Cancelled after the courier was booked, and nobody has confirmed the booking was deleted at the courier.
     *
     * @return array{courier: string, cn: ?string, packed: bool}|null
     */
    public function pendingCourierCancel(Order $order): ?array
    {
        if (! $order->active_shipment_id || (OrderStatus::map()[$order->status_id]['key'] !== 'cancelled' && ! $order->taken_back_at)) {
            return null;
        }
        $shipment = DB::table('shipments')->where('id', $order->active_shipment_id)->whereNull('cancelled_at')->whereNull('final_at')
            ->first(['courier', 'consignment_id']);

        return $shipment ? ['courier' => ucfirst((string) $shipment->courier), 'cn' => $shipment->consignment_id,
            'packed' => $order->packed_at !== null || $order->unpack_needed_at !== null, 'taken_back' => (bool) $order->taken_back_at] : null;
    }

    /** The booking was deleted at the courier (by hand, or the courier said so). */
    public function markCourierCancelled(Order $order, ?User $by, string $how = 'hand'): void
    {
        $pending = $this->pendingCourierCancel($order);
        if (! $pending) {
            return;
        }
        DB::table('shipments')->where('id', $order->active_shipment_id)->update(['cancelled_at' => now(), 'updated_at' => now()]);
        if ($order->taken_back_at) {
            // Booked by mistake and now deleted: the order has no parcel any more and may be confirmed again.
            DB::table('shipments')->where('id', $order->active_shipment_id)->update(['is_active' => false]);
            $order->forceFill(['active_shipment_id' => null, 'taken_back_at' => null])->save();
        }
        $this->note($order, 'courier', $how === 'courier'
            ? __(':c confirmed the parcel is cancelled (CN :cn).', ['c' => $pending['courier'], 'cn' => $pending['cn'] ?? '-'])
            : __('Parcel deleted at :c by hand (CN :cn), confirmed by :n.', ['c' => $pending['courier'], 'cn' => $pending['cn'] ?? '-', 'n' => $by?->name ?? '-']), $by);
    }

    public function note(Order $order, string $type, string $body, ?User $by, array $meta = []): void
    {
        DB::table('order_notes')->insert([
            'order_id' => $order->id, 'note_type' => $type, 'body' => $body, 'meta' => $meta ? json_encode($meta) : null,
            'status_at_time_id' => $order->status_id, 'user_id' => $by?->id, 'created_at' => now(),
        ]);
    }

    /**
     * Advance payments start as "pending verification". A small one (up to
     * payments.trust_up_to for the order) lowers the COD at once and is checked
     * later; a bigger one lowers it only when checked.
     */
    public function addPayment(Order $order, array $p, ?User $by): void
    {
        $method = DB::table('payment_methods')->where('id', $p['method_id'] ?? 0)->first();
        if (! $method) {
            throw ValidationException::withMessages(['advance.method_id' => __('Choose how the customer paid.')]);
        }
        if ($method->requires_trx_id && blank($p['transaction_id'] ?? null)) {
            throw ValidationException::withMessages(['advance.transaction_id' => __('Transaction ID is required for :m.', ['m' => $method->name])]);
        }
        if (! blank($p['transaction_id'] ?? null) && DB::table('order_payments')->where('method_id', $method->id)->where('transaction_id', trim($p['transaction_id']))->exists()) {
            throw ValidationException::withMessages(['advance.transaction_id' => __('This transaction ID was already used on another order.')]);
        }

        $amount = round((float) $p['amount'], 2);
        $type = $p['payment_type'] ?? 'advance';
        $counted = (float) DB::table('order_payments')->where('order_id', $order->id)->where('status', 'pending_verification')->where('counts_now', true)->sum('amount');
        $countsNow = $type === 'advance' && $amount > 0 && $counted + $amount <= (int) settings('payments.trust_up_to');
        $paidOnline = ($p['status'] ?? null) === 'verified'; // the website's payment gateway already confirmed it
        $oldCod = (float) $order->cod_amount;
        DB::table('order_payments')->insert([
            'order_id' => $order->id, 'payment_type' => $p['payment_type'] ?? 'advance', 'method_id' => $method->id,
            'amount' => round((float) $p['amount'], 2), 'transaction_id' => ($p['transaction_id'] ?? null) ? trim($p['transaction_id']) : null,
            'sender_number' => Phone::normalize($p['sender_number'] ?? null), 'status' => $paidOnline ? 'verified' : 'pending_verification', 'counts_now' => $countsNow,
            'verified_at' => $paidOnline ? now() : null, 'note' => $p['note'] ?? null,
            'received_at' => now(), 'note' => $p['note'] ?? null, 'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($order->exists && $order->grand_total !== null) {
            $this->calculator->applyPayments($order->refresh());
            $this->afterPayment($order, $oldCod, $by);
        }
        if ($paidOnline) {
            return;
        }
        app(\App\Services\NotificationService::class)->send('payment_to_check', __('Check payment: :no', ['no' => $order->order_no]),
            __(':m ৳:a, TrxID :t', ['m' => $method->name, 'a' => number_format($amount, 2), 't' => $p['transaction_id'] ?? '-']).($countsNow ? ' '.__('(already lowered the COD)') : ' '.__('(the order waits for this check)')), [
                'link' => route('payments.index'), 'subject' => ['order', $order->id],
                'user_ids' => app(DeskService::class)->managerIds(), // others (e.g. an accounts person) via Settings > Notifications
            ]);
        $this->note($order, 'payment', __('Advance ৳:a via :m (:t) recorded, waiting for verification.', ['a' => number_format((float) $p['amount'], 2), 'm' => $method->name, 't' => $p['transaction_id'] ?? '-']), $by);
    }

    public function verifyPayment(Order $order, int $paymentId, bool $approve, User $by): void
    {
        DB::transaction(function () use ($order, $paymentId, $approve, $by) {
            $payment = DB::table('order_payments')->where('id', $paymentId)->where('order_id', $order->id)->where('status', 'pending_verification')->first();
            abort_unless($payment, 404);
            $oldCod = (float) $order->fresh()->cod_amount;
            DB::table('order_payments')->where('id', $paymentId)->update([
                'status' => $approve ? 'verified' : 'rejected', 'verified_by' => $by->id, 'verified_at' => now(), 'updated_at' => now(),
            ]);
            $this->calculator->applyPayments($order->refresh());
            $this->note($order, 'payment', ($approve ? __('Payment ৳:a verified. COD is now ৳:c.', ['a' => $payment->amount, 'c' => $order->cod_amount]) : __('Payment ৳:a rejected (TrxID or amount did not match). COD is now ৳:c.', ['a' => $payment->amount, 'c' => $order->cod_amount])), $by);
            if (! $approve) {
                $this->backOnAdvanceHold($order, $by);
            }
            if (! $approve && $order->moderator_id) {
                app(\App\Services\NotificationService::class)->send('payment_to_check', __('Payment rejected: :no', ['no' => $order->order_no]),
                    __('৳:a, TrxID :t did not match. Talk to the customer.', ['a' => $payment->amount, 't' => $payment->transaction_id ?? '-']), [
                        'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'user_ids' => [$order->moderator_id],
                    ]);
            }
            $this->afterPayment($order, $oldCod, $by);
        });
    }

    /** A moderator asks an admin to let an advance-hold order go without the advance. */
    public function requestAdvanceWaiver(Order $order, User $by, string $why): void
    {
        $order->forceFill(['advance_waiver_requested_at' => now(), 'advance_waiver_requested_by' => $by->id, 'advance_waiver_note' => $why])->save();
        $this->note($order, 'system', __('Asked an admin to process it without advance: :w', ['w' => $why]), $by);
        app(\App\Services\NotificationService::class)->send('payment_to_check', __('Without advance? :no', ['no' => $order->order_no]),
            __(':n asks: :w (advance needed ৳:a)', ['n' => $by->name, 'w' => $why, 'a' => number_format((float) $order->advance_required)]), [
                'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'priority' => 'urgent',
                'user_ids' => app(DeskService::class)->managerIds(),
            ]);
    }

    /** An admin allows (the order goes to Call) or refuses (it keeps waiting for the advance). */
    public function decideAdvanceWaiver(Order $order, User $by, bool $allow): void
    {
        $asker = $order->advance_waiver_requested_by;
        if ($allow) {
            $order->forceFill(['advance_waived_by' => $by->id, 'advance_waived_at' => now(), 'advance_waiver_requested_at' => null])->save();
            $machine = app(OrderStateMachine::class);
            $machine->transition($order, $machine->heldFrom($order) === 'confirmed' ? 'confirmed' : 'record_verified', $by, 'user', null, __('Allowed without advance by :n', ['n' => $by->name]));
        } else {
            $order->forceFill(['advance_waiver_requested_at' => null])->save();
            $this->note($order, 'system', __('Without advance refused by :n: it waits for the advance.', ['n' => $by->name]), $by);
        }
        if ($asker && $asker !== $by->id) {
            app(\App\Services\NotificationService::class)->send('payment_to_check', ($allow ? __('Allowed without advance: :no', ['no' => $order->order_no]) : __('Advance still needed: :no', ['no' => $order->order_no])),
                $allow ? __(':n allowed it. It is in your Call tab.', ['n' => $by->name]) : __(':n refused. Ask the customer for the delivery charge.', ['n' => $by->name]), [
                    'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'user_ids' => [$asker],
                ]);
        }
    }

    /**
     * The advance this order needed turned out fake (rejected) before the
     * courier was booked: back on the advance hold, with its moderator.
     * Booked already: the COD update at the courier (afterPayment) covers it.
     */
    private function backOnAdvanceHold(Order $order, User $by): void
    {
        $order->refresh();
        $machine = app(OrderStateMachine::class);
        if (! $order->advance_required || $order->advance_waived_at || $order->active_shipment_id
            || ! in_array(OrderStatus::map()[$order->status_id]['key'], ['record_verified', 'no_answer', 'confirmed'], true)
            || (float) $order->advance_verified + 0.001 >= (float) $order->advance_required) {
            return;
        }
        $order->forceFill(['advance_hold_since' => now(), 'advance_reminded_at' => null])->save();
        $machine->transition($order, 'hold', null, 'system', app(DeskService::class)->advanceReasonId(), __('Advance rejected: ৳:a needed again', ['a' => number_format((float) $order->advance_required - (float) $order->advance_verified)]));
    }

    /** "Will send the advance by": the call happened, the date is noted, the hold stays. */
    public function advanceWillPayBy(Order $order, User $by, string $date): void
    {
        $order->forceFill(['hold_expected_date' => $date, 'hold_date_notified_at' => null])->save();
        $this->note($order, 'call', __('Called: will send the advance by :d', ['d' => \Illuminate\Support\Carbon::parse($date)->format('d M')]), $by, ['outcome' => 'will_pay']);
    }

    /** The order number of another open order of the same customer from the last 7 days, if any (same rule as the desk's duplicate check). */
    public function openDuplicate(Order $order): ?string
    {
        $open = array_keys(array_filter(OrderStatus::map(), fn ($s) => ! $s['final'] && $s['key'] !== 'cancelled'));

        return DB::table('orders')->where('customer_id', $order->customer_id)->where('id', '!=', $order->id)
            ->where('created_at', '>=', now()->subDays(7))->whereIn('status_id', $open)->orderByDesc('id')->value('order_no');
    }

    /** Is an advance on this order still waiting for its check without lowering the COD yet? */
    public function hasUncheckedAdvance(Order $order): bool
    {
        return DB::table('order_payments')->where('order_id', $order->id)->where('payment_type', 'advance')
            ->where('status', 'pending_verification')->where('counts_now', false)->exists();
    }

    /**
     * After money came in or a check: the courier must collect the new COD
     * (update it in the courier panel by hand, new label), and an order that
     * only waited for its advance moves on and is booked.
     */
    private function afterPayment(Order $order, float $oldCod, ?User $by): void
    {
        $order->refresh();
        $shipment = $order->active_shipment_id
            ? DB::table('shipments')->where('id', $order->active_shipment_id)->whereNull('cancelled_at')->whereNull('final_at')->first(['id'])
            : null;
        if ($shipment && abs($oldCod - (float) $order->cod_amount) > 0.001 && $by) {
            DB::table('order_amendments')->insert([
                'order_id' => $order->id, 'from_version' => $order->current_version, 'to_version' => $order->current_version,
                'status_at_time_id' => $order->status_id,
                'reason_id' => DB::table('status_reasons')->where('reason_type', 'amendment')->where('system_key', 'payment_cod')->value('id'),
                'edit_class' => 'internal', 'changes' => json_encode(['cod_amount' => [$oldCod, (float) $order->cod_amount]]), 'proposed' => json_encode([]),
                'amount_diff' => round((float) $order->cod_amount - $oldCod, 2), 'requested_by' => $by->id,
                'courier_action' => 'update_cod', 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (in_array($order->status_id, OrderStatus::idsFor(['ready_for_packaging', 'packed', 'ready_for_pickup']), true)) {
                app(\App\Services\Courier\BookingService::class)->issueLabel($order, $shipment->id, null, __('COD changed by a payment'));
            }
            app(\App\Services\NotificationService::class)->send('cod_update_needed', __('Update the COD of :no at the courier', ['no' => $order->order_no]),
                __('A payment changed the COD from ৳:a to ৳:b. Change it in the courier panel, then press Updated on the order. A new label is ready.', ['a' => $oldCod, 'b' => $order->cod_amount]), [
                    'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'priority' => 'urgent',
                    'user_ids' => array_values(array_filter([$order->moderator_id, ...app(DeskService::class)->managerIds()])),
                ]);
        }

        // Held only for the advance: the money is in (or counts), so it goes on and is booked.
        $advanceHold = DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'advance_wait')->value('id');
        $machine = app(OrderStateMachine::class);
        if (OrderStatus::map()[$order->status_id]['key'] === 'hold' && (int) $order->hold_reason_id === (int) $advanceHold
            && (float) $order->advance_verified > 0 && $machine->advanceSettled($order) && ! $this->hasUncheckedAdvance($order) && ! $order->taken_back_at) {
            // Held after Confirmed, or (a website order) already called while asking for the advance: on to booking.
            // Not called yet: to Call (address check, upsell). Chat orders: their moderator presses Confirm.
            // Same customer with another open order: never booked by itself (two parcels, two charges); the
            // moderator confirms by hand, where Confirm asks "merge first?".
            $called = $order->channel === 'web' && DB::table('order_notes')->where('order_id', $order->id)->where('note_type', 'call')->exists();
            $duplicate = $this->openDuplicate($order);
            $machine->transition($order, ($machine->heldFrom($order) === 'confirmed' || $called) && ! $duplicate ? 'confirmed' : 'record_verified', null, 'rule', null,
                $duplicate ? __('Advance received. Same customer has :no open: merge or confirm by hand.', ['no' => $duplicate]) : __('Advance received'));
        }
    }

    /** @return list<array> order_items rows with snapshots and weights */
    public function buildItems(array $rows, ?User $by, bool $trustGivenPrice, array $alreadyOnOrder = []): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => ! empty($r['variant_id']) && (float) ($r['qty'] ?? 0) > 0));
        if ($rows === []) {
            throw ValidationException::withMessages(['items' => __('Add at least one product.')]);
        }

        $onlineId = DB::table('price_lists')->where('system_key', 'online')->value('id');
        $variants = ProductVariant::with(['product:id,name', 'prices' => fn ($q) => $q->where('price_list_id', $onlineId)])
            ->whereIn('id', array_column($rows, 'variant_id'))->get()->keyBy('id');
        $mayOverride = $trustGivenPrice || $by?->can('orders.approve');

        return array_map(function ($r) use ($variants, $mayOverride, $trustGivenPrice, $alreadyOnOrder) {
            $v = $variants[$r['variant_id']] ?? throw ValidationException::withMessages(['items' => __('A product in this order no longer exists.')]);
            // A line already on the order may stay even if the item has since gone out of stock.
            if (! $trustGivenPrice && $v->availability_status === 'out_of_stock' && ! in_array($v->id, $alreadyOnOrder, true)) {
                throw ValidationException::withMessages(['items' => __(':p is out of stock.', ['p' => $v->product->name.' · '.$v->name])]);
            }
            $listPrice = (float) ($v->prices->first()?->effective() ?? 0);
            $price = $mayOverride && isset($r['unit_price']) && $r['unit_price'] !== '' ? (float) $r['unit_price'] : $listPrice;
            $qty = round((float) $r['qty'], 3);
            $discount = round((float) ($r['line_discount'] ?? 0), 2);

            return [
                'variant_id' => $v->id,
                'name_snapshot' => $v->product->name.($v->name !== 'Default' ? ' · '.$v->name : ''),
                'sku_snapshot' => $v->sku,
                'unit' => $v->unit,
                'qty' => $qty,
                'unit_price' => $price,
                'line_discount' => $discount,
                'line_total' => round(max(0, $qty * $price - $discount), 2),
                'weight_g' => \App\Support\Units::weight((float) $qty, $v->unit, $v->weight_g),
            ];
        }, $rows);
    }

    /** Append-only full snapshot of the order as it is now. */
    public function snapshot(Order $order, int $version, ?int $userId): void
    {
        DB::table('order_versions')->insert([
            'order_id' => $order->id,
            'version_no' => $version,
            'items_snapshot' => json_encode(DB::table('order_items')->where('order_id', $order->id)
                ->get(['variant_id', 'name_snapshot', 'sku_snapshot', 'unit', 'qty', 'unit_price', 'line_discount', 'line_total'])),
            'totals_snapshot' => json_encode($order->only(['subtotal', 'discount_total', 'delivery_charge', 'grand_total', 'advance_verified', 'cod_amount'])),
            'shipping_snapshot' => json_encode($order->only(['ship_name', 'ship_phone', 'ship_alt_phone', 'ship_address', 'ship_district', 'ship_thana', 'zone_id'])),
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    private function openAssignment(Order $order, int $userId, string $how, ?int $by, ?int $reasonId = null): void
    {
        DB::table('order_assignments')->insert([
            'order_id' => $order->id, 'user_id' => $userId, 'role' => 'moderator', 'how' => $how,
            'assigned_by' => $by, 'reason_id' => $reasonId, 'started_at' => now(),
        ]);
    }
}
