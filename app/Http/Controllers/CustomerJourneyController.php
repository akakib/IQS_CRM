<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * A customer's journey: every order and call in time order, with what they
 * bought, from which medium, and how much was really sold, cancelled,
 * returned or refunded. Found by phone (any way it is typed) or name.
 */
class CustomerJourneyController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $q = trim(Phone::englishDigits((string) $request->query('q', '')));
        $customers = collect();
        $tooShort = $q !== '' && mb_strlen($q) < 3;

        if ($q !== '' && ! $tooShort) {
            if ($phone = Phone::normalize($q)) {
                // The customer's own numbers first, then a number only an order carries (alt phone).
                $id = DB::table('customer_phones')->where('phone', $phone)->value('customer_id')
                    ?? Customer::where('primary_phone', $phone)->value('id')
                    ?? Order::where('ship_phone', $phone)->orWhere('ship_alt_phone', $phone)->latest('id')->value('customer_id');
                if ($id && ($c = Customer::find($id))) {
                    return redirect()->route('journey.show', $c->merged_into_id ?: $c->id);
                }
            } else {
                $customers = Customer::whereNull('merged_into_id')
                    ->where(fn ($w) => $w->where('name', 'like', $q.'%')->orWhere('name', 'like', '% '.$q.'%'))
                    ->orderByDesc('orders_count')->limit(25)->get(['id', 'name', 'primary_phone', 'orders_count', 'delivered_count', 'returned_count']);
            }
        }

        return view('journey.index', ['q' => $q, 'customers' => $customers, 'tooShort' => $tooShort]);
    }

    public function show(Customer $customer, Request $request): View|RedirectResponse
    {
        if ($customer->merged_into_id) {
            return redirect()->route('journey.show', $customer->merged_into_id);
        }
        $statuses = OrderStatus::map();
        $delivered = OrderStatus::idsFor(['delivered', 'partial_delivered']);
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
            ->get(['c.id', 'c.direction', 'c.started_at', 'c.outcome', 'c.duration_seconds', 'c.recording_url', 'c.note', 'u.name as by', 'o.order_no']);

        $events = $orders->map(fn ($o) => ['type' => 'order', 'at' => $o->created_at, 'row' => $o])
            ->concat($calls->map(fn ($c) => ['type' => 'call', 'at' => Carbon::parse($c->started_at), 'row' => $c]))
            ->sortByDesc(fn ($e) => $e['at']->getTimestamp())->values();

        // Days between one order and the next (oldest to newest).
        $dates = $orders->pluck('created_at')->sort()->values();
        $gaps = $dates->slice(1)->values()->map(fn ($d, $i) => $dates[$i]->diffInDays($d));
        $user = $request->user();

        return view('journey.show', [
            'customer' => $customer,
            'events' => $events,
            'statuses' => $statuses,
            'stats' => [
                'sold' => $orders->sum('sold'),
                'orders' => $orders->count(),
                'delivered' => $orders->where('kind', 'delivered')->count(),
                'returned' => $orders->where('kind', 'returned'),
                'cancelled' => $orders->where('kind', 'cancelled'),
                'refunded' => $orders->sum(fn ($o) => (float) $o->refunded),
                'gap' => $gaps->isEmpty() ? null : (int) round($gaps->avg()),
                'first' => $dates->first(),
                'last' => $dates->last(),
            ],
            'channels' => $orders->countBy('channel')->sortDesc(),
            'openable' => $orders->isEmpty() || ! $user->can('orders.view') ? collect() : Order::visibleTo($user)->whereIn('id', $orders->pluck('id'))->pluck('id')->flip(),
            'area' => collect([$orders->first()?->ship_thana, $orders->first()?->ship_district])->filter()->join(', '),
        ]);
    }
}
