<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A customer's whole story for the customer page: every IQS order (what was really received comes from
 * the courier, as in the profit report), every order imported from a file, every call, and the totals
 * over all of them (sales, cancelled, returned, refunded, how often they order, where orders come from).
 */
class CustomerJourney
{
    /** @return array{events: \Illuminate\Support\Collection, statuses: array, stats: array, channels: \Illuminate\Support\Collection, openable: \Illuminate\Support\Collection, area: string, orders: \Illuminate\Support\Collection, past: \Illuminate\Support\Collection, calls: \Illuminate\Support\Collection} */
    public function build(Customer $customer, User $user): array
    {
        $statuses = OrderStatus::map();
        $delivered = OrderStatus::groupIds('delivered');
        $returned = OrderStatus::idsFor(['returned']);
        $cancelled = OrderStatus::idsFor(['cancelled']);

        // One row per order; what was really received comes from the courier (as in the profit report).
        $orders = Order::query()->where('orders.customer_id', $customer->id)
            ->leftJoin('shipments as s', 's.id', '=', 'orders.active_shipment_id')
            ->with(['items:id,order_id,name_snapshot,qty,unit', 'moderator:id,name'])
            ->orderByDesc('orders.id')->limit(300)
            ->get(['orders.id', 'orders.order_no', 'orders.status_id', 'orders.channel', 'orders.source_campaign', 'orders.moderator_id', 'orders.grand_total',
                'orders.cod_amount', 'orders.advance_verified', 'orders.created_at', 'orders.ship_district', 'orders.ship_thana',
                DB::raw('COALESCE(s.payout_collected, s.collected_amount, orders.cod_amount) as collected'),
                DB::raw("(SELECT COALESCE(SUM(p.amount), 0) FROM order_payments p WHERE p.order_id = orders.id AND p.payment_type = 'refund' AND p.status = 'verified') as refunded")]);

        foreach ($orders as $o) {
            $o->kind = match (true) {
                in_array($o->status_id, $delivered, true) => 'delivered',
                in_array($o->status_id, $returned, true) => 'returned',
                in_array($o->status_id, $cancelled, true) => 'cancelled',
                default => 'open',
            };
            $o->sold = $o->kind === 'delivered' ? max(0, (float) $o->collected + (float) $o->advance_verified - (float) $o->refunded) : 0;
        }

        $phones = $customer->phones()->pluck('phone')->push($customer->primary_phone)->filter()->unique()->values();
        $calls = DB::table('customer_calls as c')->leftJoin('users as u', 'u.id', '=', 'c.user_id')->leftJoin('orders as o', 'o.id', '=', 'c.order_id')
            ->where(fn ($w) => $w->whereIn('c.order_id', $orders->pluck('id'))->orWhereIn('c.phone', $phones))
            ->whereNotNull('c.outcome')->orderByDesc('c.started_at')->limit(300)
            ->get(['c.id', 'c.direction', 'c.started_at', 'c.outcome', 'c.duration_seconds', 'c.recording_url', 'c.note', 'c.satisfaction', 'c.proof_path', 'c.proof_name', 'c.proof_old', 'c.user_id', 'u.name as by', 'o.order_no']);

        // Orders brought in from a file (WooCommerce, Pathao...): history only.
        $past = DB::table('customer_past_orders')->where('customer_id', $customer->id)->orderByDesc('ordered_at')->limit(300)->get()
            ->each(fn ($p) => $p->at = Carbon::parse($p->ordered_at));
        // Totals over every imported parcel (the timeline shows the newest 300).
        $pastBy = DB::table('customer_past_orders')->where('customer_id', $customer->id)->groupBy('status')
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(amount), 0) as amount, MIN(ordered_at) as first_at, MAX(ordered_at) as last_at')->get()->keyBy('status');
        $pastN = fn (string $s) => (int) ($pastBy[$s]->n ?? 0);
        $pastSum = fn (string $s) => (float) ($pastBy[$s]->amount ?? 0);
        $pastMedium = DB::table('customer_past_orders')->where('customer_id', $customer->id)->groupBy('medium')->selectRaw('medium, COUNT(*) as n')->pluck('n', 'medium');

        $events = $orders->map(fn ($o) => ['type' => 'order', 'at' => $o->created_at, 'row' => $o])
            ->concat($past->map(fn ($p) => ['type' => 'past', 'at' => $p->at, 'row' => $p]))
            ->concat($calls->map(fn ($c) => ['type' => 'call', 'at' => Carbon::parse($c->started_at), 'row' => $c]))
            ->sortByDesc(fn ($e) => $e['at']->getTimestamp())->values();

        // Days between one order and the next (oldest to newest).
        $dates = $orders->pluck('created_at')->concat($past->pluck('at'))->sortBy(fn ($d) => $d->getTimestamp())->values();
        // First and last across everything (also imported parcels beyond the newest 300); the average gap is the span over the orders between.
        $first = collect([$dates->first(), $pastBy->min('first_at') ? Carbon::parse($pastBy->min('first_at')) : null])->filter()->sortBy(fn ($d) => $d->getTimestamp())->first();
        $last = collect([$dates->last(), $pastBy->max('last_at') ? Carbon::parse($pastBy->max('last_at')) : null])->filter()->sortBy(fn ($d) => $d->getTimestamp())->last();
        $total = $orders->count() + $pastBy->sum('n');

        return [
            'events' => $events,
            'statuses' => $statuses,
            'stats' => [
                'sold' => $orders->sum('sold') + $pastSum('delivered'),
                'orders' => $orders->count() + $pastBy->sum('n'),
                'delivered' => $orders->where('kind', 'delivered')->count() + $pastN('delivered'),
                'returned' => [$orders->where('kind', 'returned')->count() + $pastN('returned'),
                    $orders->where('kind', 'returned')->sum('grand_total') + $pastSum('returned')],
                'cancelled' => [$orders->where('kind', 'cancelled')->count() + $pastN('cancelled'),
                    $orders->where('kind', 'cancelled')->sum('grand_total') + $pastSum('cancelled')],
                'refunded' => $orders->sum(fn ($o) => (float) $o->refunded) + $pastSum('refunded'),
                'gap' => $total > 1 && $first ? (int) round($first->diffInDays($last) / ($total - 1)) : null,
                'first' => $first,
                'last' => $last,
            ],
            'channels' => $pastMedium->reduce(fn ($c, $n, $m) => $c->put($m ?: 'other', $c->get($m ?: 'other', 0) + $n), $orders->countBy('channel'))->sortDesc(),
            'openable' => $orders->isEmpty() || ! $user->can('orders.view') ? collect() : Order::visibleTo($user)->whereIn('id', $orders->pluck('id'))->pluck('id')->flip(),
            'area' => collect([$orders->first()?->ship_thana, $orders->first()?->ship_district])->filter()->join(', '),
            'orders' => $orders,
            'past' => $past,
            'calls' => $calls,
        ];
    }
}
