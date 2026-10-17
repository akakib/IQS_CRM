<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delivery issues: opened by the hotline, rider calls or courier webhooks; each has an SLA. It goes to the order's
 * person when at work, else to the colleague free the longest, else the managers; when its person goes on a break
 * or off shift it moves on (reroute). Anyone working orders may solve any open issue: solving someone else's
 * order's issue earns points (issue_solved); unhandled ones escalate to managers.
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
            'assigned_to' => $handler = $this->whoHandles($order), 'sla_due_at' => now()->addMinutes((int) settings('orders.issue_sla_minutes')),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $label = __(ucfirst(str_replace('_', ' ', $type)));
        $this->orders->note($order, 'rider', __('Delivery issue: :t:n:r', [
            't' => $label, 'n' => $note ? ' · '.$note : '', 'r' => $riderPhone ? ' · '.__('rider :p', ['p' => $riderPhone]) : '',
        ]), $by, ['delivery_issue_id' => $id]);

        $this->notifications->send('delivery_issue', __(':no: :t', ['no' => $order->order_no, 't' => $label]), trim(($note ?? '').' '.($riderPhone ? __('Rider :p', ['p' => $riderPhone]) : '')), [
            'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'order_moderator_id' => $handler,
            'group_key' => 'delivery_issue:'.$order->id,
        ] + ($handler ? [] : ['user_ids' => app(DeskService::class)->managerIds()]));

        return $id;
    }

    public function resolve(int $issueId, string $resolution, ?string $note, User $by): void
    {
        $issue = DB::table('delivery_issues')->where('id', $issueId)->whereNull('resolved_at')->first();
        abort_unless($issue, 404);
        abort_unless($by->can('orders.edit') || $by->can('orders.approve'), 403);
        if (! in_array($resolution, self::RESOLUTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => __('Choose how it ended.')]);
        }

        DB::table('delivery_issues')->where('id', $issueId)->update([
            'resolution' => $resolution, 'resolution_note' => $note, 'resolved_by' => $by->id, 'resolved_at' => now(), 'updated_at' => now(),
        ]);
        $order = Order::find($issue->order_id);
        app(\App\Services\Points\PointHooks::class)->issueSolved($order, $by->id);
        $this->orders->note($order, 'rider', __('Issue handled: :r:n', ['r' => mb_strtolower(__(\App\Support\Labels::RESOLUTION[$resolution] ?? str_replace('_', ' ', $resolution))), 'n' => $note ? ' · '.$note : '']), $by);

        if (! DB::table('delivery_issues')->where('order_id', $order->id)->whereNull('resolved_at')->exists()) {
            $this->notifications->markActed('order', $order->id, 'delivery_issue');
        }
    }

    /**
     * Who takes an issue now: the order's person when they are at work (active, inside their shift, not on a
     * break); else the colleague at work with the fewest open issues (then the one free the longest, as orders
     * are given); else nobody by name, so the managers handle it. $not: someone just leaving (a break).
     */
    public function whoHandles(Order $order, ?int $not = null): ?int
    {
        $user = $order->moderator_id && $order->moderator_id !== $not ? \App\Models\User::find($order->moderator_id) : null;
        if ($user && $this->atWork($user)) {
            return $user->id;
        }
        $free = array_values(array_diff(app(DeskService::class)->activeModerators(), [$not]));
        if ($free === []) {
            return null;
        }
        $open = DB::table('delivery_issues')->whereNull('resolved_at')->whereIn('assigned_to', $free)->groupBy('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) as n')->pluck('n', 'assigned_to');
        $busy = DB::table('orders')->whereIn('moderator_id', $free)->whereIn('status_id', \App\Models\OrderStatus::idsFor(['new', 'record_verified']))
            ->groupBy('moderator_id')->selectRaw('moderator_id, COUNT(*) as n')->pluck('n', 'moderator_id');

        return collect($free)->sortBy(fn ($id) => [(int) ($open[$id] ?? 0), (int) ($busy[$id] ?? 0), $id])->first();
    }

    /** Open issues whose person went on a break or off shift move to whoever handles them now. @return int moved */
    public function reroute(?int $leaving = null): int
    {
        $moved = 0;
        $rows = DB::table('delivery_issues')->whereNull('resolved_at')->whereNotNull('assigned_to')
            ->when($leaving, fn ($q) => $q->where('assigned_to', $leaving))->get(['id', 'order_id', 'assigned_to']);
        foreach ($rows as $r) {
            $person = \App\Models\User::find($r->assigned_to);
            if (! $leaving && $person && $this->atWork($person)) {
                continue;
            }
            $order = Order::find($r->order_id);
            $next = $this->whoHandles($order, $r->assigned_to);
            DB::table('delivery_issues')->where('id', $r->id)->update(['assigned_to' => $next, 'updated_at' => now()]);
            $name = $next ? \App\Models\User::whereKey($next)->value('name') : __('the managers');
            $this->orders->note($order, 'rider', __('Delivery issue moved to :n (:p is away).', ['n' => $name, 'p' => $person?->name ?? '-']), null, ['delivery_issue_id' => $r->id]);
            $this->notifications->send('delivery_issue', __(':no: delivery issue given to you', ['no' => $order->order_no]), null, [
                'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'group_key' => 'delivery_issue:'.$order->id,
                'user_ids' => $next ? [$next] : app(DeskService::class)->managerIds(),
            ]);
            $moved++;
        }

        return $moved;
    }

    private function atWork(\App\Models\User $user): bool
    {
        return $user->is_active && ! $user->current_break_id && app(\App\Services\Work\WorkCalendar::class)->isWorking($user->id);
    }

    /** Past-SLA issues go to managers once. @return int how many escalated */
    public function escalate(): int
    {
        $due = DB::table('delivery_issues as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereNull('i.resolved_at')->whereNull('i.escalated_at')->where('i.sla_due_at', '<=', now())
            ->get(['i.id', 'i.order_id', 'i.issue_type', 'i.assigned_to', 'o.order_no']);

        $managers = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->whereIn('r.system_key', ['manager', 'owner'])->whereNull('r.deleted_at')->distinct()->pluck('ur.user_id')->all();

        foreach ($due as $i) {
            DB::table('delivery_issues')->where('id', $i->id)->update(['escalated_at' => now(), 'updated_at' => now()]);
            $order = Order::find($i->order_id);
            // A minus point only for someone who had the issue while at work; a shift that ended or a break just hands it on.
            $person = $i->assigned_to ? \App\Models\User::find($i->assigned_to) : null;
            $atWork = $person && $person->is_active && ! $person->current_break_id && app(\App\Services\Work\WorkCalendar::class)->isWorking($person->id);
            $this->orders->note($order, 'rider', $atWork ? __('Issue not handled in time: sent to managers.') : __('Issue still open and its person is off work: sent to managers.'), null, ['delivery_issue_id' => $i->id]);
            if ($atWork) {
                app(\App\Services\Points\PointHooks::class)->escalated($order, $person->id); // the person it was with, not always the order's
            }
            $this->notifications->send('delivery_issue', __('Overdue: :no :t', ['no' => $i->order_no, 't' => str_replace('_', ' ', $i->issue_type)]), null, [
                'link' => route('orders.show', $i->order_id), 'subject' => ['order', $i->order_id], 'user_ids' => $managers, 'priority' => 'urgent',
            ]);
        }

        return $due->count();
    }
}
