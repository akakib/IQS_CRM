<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The agent's working screen: orders to call (own Record verified / No
 * answer), one-click call outcomes, and "Take next" for the oldest waiting
 * order. Every outcome writes a call note, which is the proof that a call
 * happened before a website order is confirmed.
 */
class CallQueueController extends Controller
{
    public function __construct(private OrderService $orders, private OrderStateMachine $machine) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $ids = OrderStatus::idsFor(['new', 'record_verified', 'no_answer']);

        $orders = Order::query()
            ->select(['id', 'order_no', 'channel', 'status_id', 'customer_id', 'ship_name', 'ship_phone', 'ship_thana', 'ship_district', 'grand_total', 'cod_amount', 'is_duplicate_flag', 'customer_note', 'created_at', 'packed_version', 'current_version', 'edited_after_pack'])
            ->with(['items:id,order_id,name_snapshot,qty,unit,variant_id', 'items.variant:id,availability_status,expected_restock_date', 'customer:id,orders_count,delivered_count,returned_count,risk_level'])
            ->where('owner_id', $user->id)->whereIn('status_id', $ids)
            ->orderByRaw('status_id = ? desc', [OrderStatus::idFor('record_verified')])->orderBy('id')
            ->limit(50)->get();

        $attempts = DB::table('order_events')->whereIn('order_id', $orders->pluck('id'))
            ->where('to_status_id', OrderStatus::idFor('no_answer'))->selectRaw('order_id, COUNT(*) as n, MAX(created_at) as last')->groupBy('order_id')->get()->keyBy('order_id');

        return view('orders.queue', [
            'orders' => $orders,
            'attempts' => $attempts,
            'waiting' => Order::whereNull('owner_id')->whereIn('status_id', OrderStatus::idsFor(['new', 'record_verified']))->count(),
            'statuses' => OrderStatus::map(),
            'maxNoAnswer' => (int) settings('orders.max_no_answer'),
            'reasons' => ['hold' => \App\Models\StatusReason::options('hold'), 'cancel' => \App\Models\StatusReason::options('cancel')],
        ]);
    }

    /** Claim the oldest waiting order (verified ones first). */
    public function takeNext(Request $request): RedirectResponse
    {
        $next = Order::whereNull('owner_id')->whereIn('status_id', OrderStatus::idsFor(['record_verified', 'new']))
            ->orderByRaw('status_id = ? desc', [OrderStatus::idFor('record_verified')])->orderBy('id')->first();
        if (! $next) {
            return back()->with('error', __('No order is waiting.'));
        }
        $this->orders->claim($next, $request->user());

        return back()->with('success', __(':no is yours now.', ['no' => $next->order_no]));
    }

    public function logCall(Order $order, Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($order->owner_id === $user->id, 403);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['confirmed', 'no_answer', 'callback', 'hold', 'cancelled'])],
            'note' => ['nullable', 'string', 'max:500'],
            'reason_id' => ['nullable', 'integer'],
        ]);

        $labels = ['confirmed' => __('Called: customer confirmed'), 'no_answer' => __('Called: no answer'), 'callback' => __('Called: asked to call back'),
            'hold' => __('Called: put on hold'), 'cancelled' => __('Called: cancelled')];
        $this->orders->note($order, 'call', trim($labels[$data['outcome']].(! empty($data['note']) ? ' · '.$data['note'] : '')), $user, ['outcome' => $data['outcome']]);

        if ($data['outcome'] === 'no_answer') {
            $tries = DB::table('order_events')->where('order_id', $order->id)->where('to_status_id', OrderStatus::idFor('no_answer'))->count();
            if ($tries >= (int) settings('orders.max_no_answer')) {
                throw ValidationException::withMessages(['order' => __(':n tries done. Hold or cancel this order.', ['n' => $tries])]);
            }
        }

        if ($data['outcome'] !== 'callback') {
            $this->machine->transition($order, $data['outcome'], $user, 'user', $data['reason_id'] ?? null);
        }

        return back()->with('success', __('Saved: :o.', ['o' => $labels[$data['outcome']]]));
    }
}
