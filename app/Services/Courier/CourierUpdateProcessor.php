<?php

namespace App\Services\Courier;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Courier\Data\CourierUpdate;
use App\Services\NotificationService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Applies one courier update (webhook or re-sync) to its order:
 * tracking messages go to the timeline; delivery statuses move the order
 * (system source) or open a delivery issue for the assigned moderator when a decision
 * is needed (hold, partial / cancel waiting for approval, unknown).
 */
class CourierUpdateProcessor
{
    /**
     * courier status => our status. null = no status change, open an issue. 'none' = Steadfast says this from the
     * moment a parcel is created until it is delivered or returned (in_review, pending): no change for us.
     */
    private const MAP = [
        'in_review' => 'none', 'pending' => 'none', 'in_transit' => 'in_transit',
        'delivered' => 'delivered', 'partial_delivered' => 'partial_delivered', 'cancelled' => 'returned',
        'hold' => null, 'delivered_approval_pending' => null, 'partial_delivered_approval_pending' => null,
        'cancelled_approval_pending' => null, 'unknown_approval_pending' => null, 'unknown' => null,
    ];

    private const ISSUE_TYPE = [
        'hold' => 'hold', 'partial_delivered_approval_pending' => 'partial', 'cancelled_approval_pending' => 'cancel',
        'delivered_approval_pending' => 'other', 'unknown_approval_pending' => 'other', 'unknown' => 'other',
    ];

    public function __construct(
        private OrderStateMachine $machine,
        private OrderService $orders,
        private NotificationService $notifications,
    ) {}

    /** @return string what happened (for logs and tests) */
    public function apply(CourierUpdate $u, ?int $eventId = null): string
    {
        $shipment = DB::table('shipments')
            ->when($u->consignmentId, fn ($q) => $q->where('consignment_id', $u->consignmentId), fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
        if (! $shipment && $u->invoice) {
            $shipment = DB::table('shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')
                ->where('o.order_no', preg_replace('/-\d+$/', '', $u->invoice))->where('s.is_active', true)->select('s.*')->first();
        }
        if (! $shipment) {
            $this->mark($eventId, 'No shipment for this consignment / invoice.');

            return 'unknown_shipment';
        }
        DB::table('courier_events')->where('id', $eventId)->update(['shipment_id' => $shipment->id]);

        $order = Order::find($shipment->order_id);
        if (! $shipment->is_active) {
            $this->mark($eventId, null);

            return 'inactive_shipment'; // an old booking (rebooked); ignore its updates
        }

        $this->checkCod($order, $shipment, $u);

        if ($u->type === 'tracking_update') {
            if ($u->message) {
                $this->orders->note($order, 'courier', __('Courier: :m', ['m' => $u->message]), null);
            }
            $this->mark($eventId, null);

            return 'tracking_note';
        }

        $status = $u->status ?? 'unknown';
        DB::table('shipments')->where('id', $shipment->id)->update(array_filter([
            'courier_status' => $status,
            'delivery_charge' => $u->deliveryCharge,
            'updated_at' => now(),
        ], fn ($v) => $v !== null));

        $target = self::MAP[$status] ?? null;
        $current = OrderStatus::map()[$order->status_id];
        if ($target === 'none') {
            $this->mark($eventId, null);

            return 'no_change';
        }
        if ($target === null) {
            $this->openIssue($order, $shipment, self::ISSUE_TYPE[$status] ?? 'other', __('Courier status: :s', ['s' => str_replace('_', ' ', $status)]));
            $this->mark($eventId, null);

            return 'issue_opened';
        }

        if (($current['key'] === 'cancelled' || $order->taken_back_at) && $status === 'cancelled') {
            $this->orders->markCourierCancelled($order, null, 'courier');
            $this->mark($eventId, null);

            return 'cancel_confirmed';
        }
        if ($current['final'] && in_array($target, ['delivered', 'partial_delivered', 'returned'], true) && ! $shipment->final_at && $current['key'] !== $target) {
            DB::table('shipments')->where('id', $shipment->id)->update(['final_at' => now(), 'collected_amount' => $target === 'returned' ? 0 : $u->codAmount]);
            $this->orders->note($order, 'courier', __('Courier says :s, but the order is :o. Check what happened to this parcel.', ['s' => str_replace('_', ' ', $target), 'o' => $current['name']]), null);
            $this->notifications->send('delivery_issue', __(':no: courier says :s', ['no' => $order->order_no, 's' => str_replace('_', ' ', $target)]),
                __('The order is :o. Check the parcel (and the money) with the courier.', ['o' => $current['name']]), [
                    'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'priority' => 'urgent',
                    'user_ids' => app(\App\Services\Orders\DeskService::class)->managerIds(),
                ]);
            $this->mark($eventId, null);

            return 'closed_order_outcome';
        }
        if ($current['key'] === $target || $current['final']) {
            $this->mark($eventId, null);

            return 'no_change';
        }
        // kh4: cancelled by the courier before anyone picked it up (review rejected, deleted in the panel): not a customer return.
        if ($status === 'cancelled' && in_array($current['key'], ['confirmed', 'ready_for_packaging', 'packed', 'ready_for_pickup'], true)) {
            $this->openIssue($order, $shipment, 'cancel', __('The courier cancelled this parcel before pickup. Book it again or cancel the order.'));
            $this->mark($eventId, null);

            return 'cancelled_before_pickup';
        }

        try {
            $this->applyStatus($order, $current, $target, $u, $shipment);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->openIssue($order, $shipment, 'other', __('Courier status :s could not be applied while the order is :o.', ['s' => str_replace('_', ' ', $status), 'o' => $current['name']]));
            $this->mark($eventId, $e->getMessage());

            return 'issue_opened';
        }
        $this->mark($eventId, null);

        return $target;
    }

    private function applyStatus(Order $order, array $current, string $target, CourierUpdate $u, object $shipment): void
    {
        DB::transaction(function () use ($order, $current, $target, $u, $shipment) {
            if (in_array($current['key'], ['ready_for_packaging', 'packed', 'ready_for_pickup'], true)) {
                $this->machine->transition($order, 'in_transit', null, 'webhook', null, __('Courier picked up without a handover scan.'));
            }
            if ($target === 'in_transit') {
                return;
            }

            $reasonId = $target === 'returned'
                ? DB::table('status_reasons')->where('reason_type', 'return')->where('system_key', 'unclassified')->value('id')
                : null;
            $this->machine->transition($order, $target, null, 'webhook', $reasonId, $u->message);

            // Partly delivered without an amount from the courier: unknown (the payout statement fills it), never the full COD.
            $collected = match (true) {
                $target === 'returned' => 0,
                $u->codAmount !== null => $u->codAmount,
                $target === 'partial_delivered' => null,
                default => (float) $shipment->cod_amount,
            };
            DB::table('shipments')->where('id', $shipment->id)->update(['final_at' => now(), 'collected_amount' => $collected]);
            // The customer's delivered / returned counts are counted again by the status change itself.
            if ($target === 'returned') {
                // The courier's charge for a return, as set today (Settings, Returns); kept on the order for the loss.
                DB::table('orders')->where('id', $order->id)->update(['return_charge' => (float) settings('returns.courier_charge')]);
                $this->openIssue($order, $shipment, 'cancel', __('Parcel returned: set the return reason.'));
            }
            app(NotificationService::class)->markActed('order', $order->id, 'delivery_issue');
        });
    }

    private function openIssue(Order $order, object $shipment, string $type, string $note): void
    {
        $open = DB::table('delivery_issues')->where('order_id', $order->id)->where('issue_type', $type)->whereNull('resolved_at')->exists();
        if ($open) {
            return;
        }
        $owner = app(\App\Services\Orders\DeliveryIssueService::class)->whoHandles($order); // at work now, or the managers
        $id = DB::table('delivery_issues')->insertGetId([
            'order_id' => $order->id, 'shipment_id' => $shipment->id, 'issue_type' => $type, 'note' => $note,
            'opened_by' => null, 'assigned_to' => $owner,
            'sla_due_at' => now()->addMinutes((int) settings('orders.issue_sla_minutes')),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->orders->note($order, 'courier', $note, null, ['delivery_issue_id' => $id]);
        $this->notifications->send('delivery_issue', __(':no: :n', ['no' => $order->order_no, 'n' => $note]), $order->ship_name, [
            'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'order_moderator_id' => $owner,
            'group_key' => 'delivery_issue:'.$order->id,
        ] + ($owner ? [] : ['user_ids' => app(\App\Services\Orders\DeskService::class)->managerIds()]));
    }

    /** Before delivery the courier's COD must be ours; a different one means the parcel was changed outside IQS. */
    private function checkCod(Order $order, object $shipment, CourierUpdate $u): void
    {
        $final = in_array($u->status, ['delivered', 'partial_delivered', 'cancelled'], true) || OrderStatus::map()[$order->status_id]['final'];
        // Skip: same as ours, already flagged, or the very amount a manager already checked.
        $checked = $order->courier_mismatch_cod !== null && abs($u->codAmount - (float) $order->courier_mismatch_cod) < 1;
        if ($u->codAmount === null || $final || abs($u->codAmount - (float) $order->cod_amount) < 1 || $order->courier_mismatch_at || $checked) {
            return;
        }
        $order->forceFill(['courier_mismatch_at' => now(), 'courier_mismatch_cod' => $u->codAmount])->save();
        $this->orders->note($order, 'courier', __('Courier COD is ৳:c but the order says ৳:o: the parcel was changed outside IQS. Handover blocked until a manager checks.', [
            'c' => number_format($u->codAmount), 'o' => number_format((float) $order->cod_amount)]), null);
        $this->notifications->send('delivery_issue', __(':no: courier COD differs', ['no' => $order->order_no]),
            __('Steadfast ৳:c, order ৳:o. Check what the customer wants and fix the order.', ['c' => number_format($u->codAmount), 'o' => number_format((float) $order->cod_amount)]), [
                'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'priority' => 'urgent',
                'user_ids' => array_values(array_unique(array_filter([$order->moderator_id, ...app(\App\Services\Orders\DeskService::class)->managerIds()]))),
            ]);
    }

    private function mark(?int $eventId, ?string $error): void
    {
        if ($eventId) {
            DB::table('courier_events')->where('id', $eventId)->update(['processed_at' => now(), 'error' => $error]);
        }
    }
}
