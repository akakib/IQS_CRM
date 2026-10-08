<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Packaging\ReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Returns report over a date range (by the day the courier sent it back):
 * how many, the rate against delivered, the loss (courier charges, damaged
 * and missing items at cost), and the split: damaged items, reason, product,
 * person, area.
 */
class ReturnReportController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = Carbon::parse($data['to'] ?? today())->min(today());
        $from = Carbon::parse($data['from'] ?? $to->copy()->subDays(29));
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        // Each returned order once, on the day it came back, with its reason.
        $events = DB::table('order_events')->whereIn('to_status_id', OrderStatus::idsFor(ReturnService::STATES))->whereBetween('created_at', $range)
            ->orderBy('id')->get(['order_id', 'reason_id', 'to_status_id'])->keyBy('order_id');
        $ids = $events->keys()->all();
        $orders = DB::table('orders as o')->leftJoin('users as u', 'u.id', '=', 'o.moderator_id')->whereIn('o.id', $ids)
            ->get(['o.id', 'o.order_no', 'o.ship_district', 'o.return_charge', 'o.return_received_at', 'u.name as person', 'o.moderator_id'])->keyBy('id');
        $delivered = DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')->where('e.to_status_id', OrderStatus::idFor('delivered'))
            ->whereBetween('e.created_at', $range)->get(['o.moderator_id', 'o.ship_district']);

        // Damaged or missing, by product, at what it cost us.
        $bad = DB::table('return_items as r')->join('order_items as i', 'i.id', '=', 'r.order_item_id')->join('orders as o', 'o.id', '=', 'r.order_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'r.variant_id')
            ->whereIn('r.condition', ['damaged', 'missing'])->whereBetween('r.created_at', $range)
            ->get(['r.variant_id', 'r.condition', 'r.qty', 'i.name_snapshot', 'i.cost_price_snapshot', 'v.cost_price', 'o.id as order_id', 'o.order_no']);
        $cost = fn ($r) => (float) $r->qty * (float) ($r->cost_price_snapshot ?? $r->cost_price ?? 0);
        $damagedItems = $bad->groupBy(fn ($r) => $r->variant_id ?: $r->name_snapshot)->map(fn ($g) => [
            'name' => $g->first()->name_snapshot,
            'damaged' => (float) $g->where('condition', 'damaged')->sum('qty'), 'missing' => (float) $g->where('condition', 'missing')->sum('qty'),
            'cost' => $g->sum($cost), 'orders' => $g->map(fn ($r) => ['id' => $r->order_id, 'no' => $r->order_no])->unique('id')->values(),
        ])->sortByDesc('cost')->values();

        $reasons = DB::table('status_reasons')->where('reason_type', 'return')->pluck('label_en', 'id');
        $byReason = $events->groupBy('reason_id')->map(fn ($g, $id) => ['label' => __($reasons[$id] ?? 'Not set'), 'n' => $g->count()])->sortByDesc('n')->values();

        $byProduct = DB::table('order_items')->whereIn('order_id', $ids)->groupBy('name_snapshot')
            ->selectRaw('name_snapshot as name, COUNT(DISTINCT order_id) as parcels, SUM(qty) as qty')->orderByDesc('parcels')->limit(50)->get();

        $rate = fn (int $ret, int $del) => $ret + $del ? round($ret * 100 / ($ret + $del)) : null;
        $delByPerson = $delivered->countBy('moderator_id');
        $byPerson = $orders->groupBy('moderator_id')->map(fn ($g, $id) => ['name' => $g->first()->person ?? __('Nobody'), 'returned' => $g->count(),
            'delivered' => (int) ($delByPerson[$id] ?? 0), 'rate' => $rate($g->count(), (int) ($delByPerson[$id] ?? 0))])->sortByDesc('returned')->values();
        $delByArea = $delivered->countBy('ship_district');
        $byArea = $orders->groupBy(fn ($o) => $o->ship_district ?: __('Unknown'))->map(fn ($g, $d) => ['name' => $d, 'returned' => $g->count(),
            'delivered' => (int) ($delByArea[$d] ?? 0), 'rate' => $rate($g->count(), (int) ($delByArea[$d] ?? 0))])->sortByDesc('returned')->values();

        $returned = count($ids);
        $charges = (float) $orders->sum('return_charge');
        $damagedCost = (float) $bad->where('condition', 'damaged')->sum($cost);
        $missingCost = (float) $bad->where('condition', 'missing')->sum($cost);

        return view('reports.returns', [
            'from' => $from, 'to' => $to,
            'total' => ['returned' => $returned, 'rate' => $rate($returned, $delivered->count()), 'charges' => $charges, 'damaged' => $damagedCost, 'missing' => $missingCost,
                'loss' => $charges + $damagedCost + $missingCost, 'notBack' => $orders->whereNull('return_received_at')->count()],
            'damagedItems' => $damagedItems, 'byReason' => $byReason, 'byProduct' => $byProduct, 'byPerson' => $byPerson, 'byArea' => $byArea,
        ]);
    }

    /** The real reason of a return (it arrives as "not set yet" from the courier). */
    public function reason(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['reason_id' => ['required', 'integer']]);
        $reason = DB::table('status_reasons')->where('id', $data['reason_id'])->where('reason_type', 'return')->first() ?? abort(422);
        $event = DB::table('order_events')->where('order_id', $order->id)->whereIn('to_status_id', OrderStatus::idsFor(ReturnService::STATES))->orderByDesc('id')->first() ?? abort(422);
        DB::table('order_events')->where('id', $event->id)->update(['reason_id' => $reason->id]);
        app(\App\Services\Orders\OrderService::class)->note($order, 'system', __('Return reason set to :r by :n.', ['r' => __($reason->label_en), 'n' => $request->user()->name]), $request->user());

        return back()->with('success', __(':no: reason saved.', ['no' => $order->order_no]));
    }
}
