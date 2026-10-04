<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delivery issues: the order owner is responsible end-to-end. Opened by the
 * hotline or by courier webhooks; each has an SLA; unhandled ones escalate
 * to managers (and count against the owner's KPI later).
 */
class DeliveryIssueService
{
    public const TYPES = ['no_answer', 'partial', 'cancel', 'exchange', 'address', 'hold', 'other'];

    public const RESOLUTIONS = ['delivered', 'rescheduled', 'partial', 'returned', 'exchange_created', 'solved'];

    public function __construct(private OrderService $orders, private NotificationService $notifications) {}

    public function open(Order $order, string $type, ?string $riderPhone, ?string $note, ?User $by): int
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['issue_type' => __('Choose the problem.')]);
        }
        $shipmentId = $order->active_shipment_id;
        $id = DB::table('delivery_issues')->insertGetId([
            'order_id' => $order->id, 'shipment_id' => $shipmentId, 'issue_type' => $type,
            'rider_phone' => Phone::normalize($riderPhone), 'note' => $note, 'opened_by' => $by?->id,
            'assigned_to' => $order->owner_id, 'sla_due_at' => now()->addMinutes((int) settings('orders.issue_sla_minutes')),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $label = __(ucfirst(str_replace('_', ' ', $type)));
        $this->orders->note($order, 'rider', __('Delivery issue: :t:n:r', [
            't' => $label, 'n' => $note ? ' · '.$note : '', 'r' => $riderPhone ? ' · '.__('rider :p', ['p' => $riderPhone]) : '',
        ]), $by, ['delivery_issue_id' => $id]);

        $this->notifications->send('delivery_issue', __(':no: :t', ['no' => $order->order_no, 't' => $label]), trim(($note ?? '').' '.($riderPhone ? __('Rider :p', ['p' => $riderPhone]) : '')), [
            'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'order_owner_id' => $order->owner_id,
            'group_key' => 'delivery_issue:'.$order->id,
        ]);

        return $id;
    }

    public function resolve(int $issueId, string $resolution, ?string $note, User $by): void
    {
        $issue = DB::table('delivery_issues')->where('id', $issueId)->whereNull('resolved_at')->first();
        abort_unless($issue, 404);
        abort_unless($issue->assigned_to === $by->id || $by->can('orders.approve'), 403);
        if (! in_array($resolution, self::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => __('Choose how it ended.')]);
        }

        DB::table('delivery_issues')->where('id', $issueId)->update([
            'resolution' => $resolution, 'resolution_note' => $note, 'resolved_by' => $by->id, 'resolved_at' => now(), 'updated_at' => now(),
        ]);
        $order = Order::find($issue->order_id);
        $this->orders->note($order, 'rider', __('Issue handled: :r:n', ['r' => str_replace('_', ' ', $resolution), 'n' => $note ? ' · '.$note : '']), $by);

        if (! DB::table('delivery_issues')->where('order_id', $order->id)->whereNull('resolved_at')->exists()) {
            $this->notifications->markActed('order', $order->id, 'delivery_issue');
        }
    }

    /** Past-SLA issues go to managers once. @return int how many escalated */
    public function escalate(): int
    {
        $due = DB::table('delivery_issues as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereNull('i.resolved_at')->whereNull('i.escalated_at')->where('i.sla_due_at', '<=', now())
            ->get(['i.id', 'i.order_id', 'i.issue_type', 'o.order_no']);

        $managers = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->whereIn('r.system_key', ['manager', 'owner'])->whereNull('r.deleted_at')->distinct()->pluck('ur.user_id')->all();

        foreach ($due as $i) {
            DB::table('delivery_issues')->where('id', $i->id)->update(['escalated_at' => now(), 'updated_at' => now()]);
            $order = Order::find($i->order_id);
            $this->orders->note($order, 'rider', __('Issue not handled in time: sent to managers.'), null, ['delivery_issue_id' => $i->id]);
            app(\App\Services\Points\PointHooks::class)->escalated($order);
            $this->notifications->send('delivery_issue', __('Overdue: :no :t', ['no' => $i->order_no, 't' => str_replace('_', ' ', $i->issue_type)]), null, [
                'link' => route('orders.show', $i->order_id), 'subject' => ['order', $i->order_id], 'user_ids' => $managers, 'priority' => 'urgent',
            ]);
        }

        return $due->count();
    }
}
