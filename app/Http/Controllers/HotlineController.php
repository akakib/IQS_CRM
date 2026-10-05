<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Orders\DeliveryIssueService;
use App\Services\Orders\OrderService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Rider hotline: find a parcel fast, solve small things here, hand big ones to the assigned moderator. */
class HotlineController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $results = collect();

        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $phone = Phone::normalize($q);
            $results = Order::query()
                ->select(['orders.id', 'order_no', 'status_id', 'moderator_id', 'ship_name', 'ship_phone', 'cod_amount', 'created_at', 'packed_version', 'current_version', 'edited_after_pack', 'is_duplicate_flag'])
                ->with('moderator:id,name')
                ->where(fn ($w) => $w
                    ->where('order_no', strtoupper($q))
                    ->when($phone, fn ($w) => $w->orWhere('ship_phone', $phone)->orWhere('ship_alt_phone', $phone))
                    ->when(strlen($digits) >= 5, fn ($w) => $w->orWhereIn('orders.id', DB::table('shipments')->select('order_id')->where('consignment_id', $digits)))
                    ->when(strlen($digits) < 5, fn ($w) => $w->orWhere('ship_name', 'like', $q.'%')))
                ->orderByDesc('orders.id')->limit(10)->get();
        }

        $selected = $results->count() === 1 ? $results->first() : ($request->integer('order') ? Order::find($request->integer('order')) : null);
        $detail = null;
        if ($selected) {
            $selected->load(['items:id,order_id,name_snapshot,qty,unit', 'moderator:id,name']);
            $detail = [
                'order' => $selected,
                'shipment' => DB::table('shipments')->where('id', $selected->active_shipment_id)->first(['consignment_id', 'courier_status', 'cod_amount']),
                'notes' => DB::table('order_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.user_id')->where('n.order_id', $selected->id)
                    ->orderByDesc('n.id')->limit(20)->get(['n.body', 'n.note_type', 'n.created_at', 'u.name as user']),
                'issues' => DB::table('delivery_issues')->where('order_id', $selected->id)->whereNull('resolved_at')->get(),
            ];
        }

        return view('hotline.index', ['q' => $q, 'results' => $results, 'detail' => $detail, 'statuses' => OrderStatus::map()]);
    }

    public function solved(Order $order, Request $request, OrderService $orders): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500'], 'rider_phone' => ['nullable', 'string', 'max:20']]);
        $orders->note($order, 'rider', __('Hotline solved: :n', ['n' => $data['note']]).(! empty($data['rider_phone']) ? ' · '.__('rider :p', ['p' => $data['rider_phone']]) : ''), $request->user());

        return back()->with('success', __('Saved on the order timeline.'));
    }

    public function openIssue(Order $order, Request $request, DeliveryIssueService $issues): RedirectResponse
    {
        $data = $request->validate([
            'issue_type' => ['required', Rule::in(DeliveryIssueService::TYPES)],
            'rider_phone' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $issues->open($order, $data['issue_type'], $data['rider_phone'] ?? null, $data['note'] ?? null, $request->user());

        return back()->with('success', __('Sent to :n with a timer.', ['n' => $order->moderator?->name ?? __('the managers')]));
    }

    public function issues(Request $request): View
    {
        $user = $request->user();
        $all = $user->can('orders.approve');

        $issues = DB::table('delivery_issues as i')->join('orders as o', 'o.id', '=', 'i.order_id')->leftJoin('users as u', 'u.id', '=', 'i.assigned_to')
            ->whereNull('i.resolved_at')
            ->when(! $all, fn ($q) => $q->where('i.assigned_to', $user->id))
            ->orderBy('i.sla_due_at')
            ->get(['i.*', 'o.order_no', 'o.ship_name', 'o.cod_amount', 'u.name as moderator']);

        return view('hotline.issues', ['issues' => $issues, 'all' => $all]);
    }

    public function resolve(int $issue, Request $request, DeliveryIssueService $issues): RedirectResponse
    {
        $data = $request->validate(['resolution' => ['required', Rule::in(DeliveryIssueService::RESOLUTIONS)], 'note' => ['nullable', 'string', 'max:500']]);
        $issues->resolve($issue, $data['resolution'], $data['note'] ?? null, $request->user());

        return back()->with('success', __('Issue closed.'));
    }
}
