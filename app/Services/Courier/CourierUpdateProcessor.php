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
 * (system source) or open a delivery issue for the owner when a decision
 * is needed (hold, partial / cancel waiting for approval, unknown).
 */
class CourierUpdateProcessor
{
    /** courier status => our status (null = no status change, open an issue) */
    private const MAP = [
        'in_review' => 'in_transit', 'pending' => 'in_transit', 'in_transit' => 'in_transit',
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
                ->where('o.order_no', $u->invoice)->where('s.is_active', true)->select('s.*')->first();
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
        if ($target === null) {
            $this->openIssue($order, $shipment, self::ISSUE_TYPE[$status] ?? 'other', __('Courier status: :s', ['s' => str_replace('_', ' ', $status)]));
            $this->mark($eventId, null);

            return 'issue_opened';
        }

        $current = OrderStatus::map()[$order->status_id];
        if ($current['key'] === $target || $current['final']) {
            $this->mark($eventId, null);

            return 'no_change';
        }

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

            $collected = $target === 'returned' ? 0 : ($u->codAmount ?? (float) $shipment->cod_amount);
            DB::table('shipments')->where('id', $shipment->id)->update(['final_at' => now(), 'collected_amount' => $collected]);
            DB::table('customers')->where('id', $order->customer_id)->update(
                $target === 'returned' ? ['returned_count' => DB::raw('returned_count + 1')] : ['delivered_count' => DB::raw('delivered_count + 1')]
            );
            if ($target === 'returned') {
                $this->openIssue($order, $shipment, 'cancel', __('Parcel returned: set the return reason.'));
            }
            app(NotificationService::class)->markActed('order', $order->id, 'delivery_issue');
        });
        $this->mark($eventId, null);

        return $target;
    }

    private function openIssue(Order $order, object $shipment, string $type, string $note): void
    {
        $open = DB::table('delivery_issues')->where('order_id', $order->id)->where('issue_type', $type)->whereNull('resolved_at')->exists();
        if ($open) {
            return;
        }
        $id = DB::table('delivery_issues')->insertGetId([
            'order_id' => $order->id, 'shipment_id' => $shipment->id, 'issue_type' => $type, 'note' => $note,
            'opened_by' => null, 'assigned_to' => $order->owner_id,
            'sla_due_at' => now()->addMinutes((int) settings('orders.issue_sla_minutes')),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->orders->note($order, 'courier', $note, null, ['delivery_issue_id' => $id]);
        $this->notifications->send('delivery_issue', __(':no: :n', ['no' => $order->order_no, 'n' => $note]), $order->ship_name, [
            'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'order_owner_id' => $order->owner_id,
            'group_key' => 'delivery_issue:'.$order->id,
        ]);
    }

    private function mark(?int $eventId, ?string $error): void
    {
        if ($eventId) {
            DB::table('courier_events')->where('id', $eventId)->update(['processed_at' => now(), 'error' => $error]);
        }
    }
}
