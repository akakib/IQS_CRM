<?php

namespace App\Http\Controllers;

use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\StatusReason;
use App\Models\User;
use App\Services\Orders\DeliveryCharges;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use App\Support\Lists\ListState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders, private OrderStateMachine $machine) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $statusKeys = array_filter(array_column(OrderStatus::map(), 'key'));
        $list = ListState::from($request, ['id', 'grand_total'], [
            'tab' => ['take', 'mine', 'all'],
            'status' => $statusKeys,
            'channel' => ['web', 'messenger', 'whatsapp', 'phone', 'b2b'],
            'owner' => 'int',
            'from' => 'date',
            'to' => 'date',
        ], 'desc');
        $tab = $list->filter('tab') ?? 'all';
        $working = OrderStatus::idsFor(['new', 'record_verified']);
        $finals = array_keys(array_filter(OrderStatus::map(), fn ($s) => $s['final']));
        $q = trim($list->search);
        $digits = preg_replace('/\D/', '', $q);

        // Large, fast-growing list: simple pagination (no COUNT), indexed filters.
        $orders = Order::query()->visibleTo($user)
            ->select(['id', 'order_no', 'channel', 'status_id', 'owner_id', 'ship_name', 'ship_phone', 'grand_total', 'cod_amount',
                'is_duplicate_flag', 'packed_version', 'current_version', 'edited_after_pack', 'created_at'])
            ->with('owner:id,name')
            ->when($tab === 'take', fn ($w) => $w->whereNull('owner_id')->whereIn('status_id', $working))
            ->when($tab === 'mine', fn ($w) => $w->where('owner_id', $user->id)->whereNotIn('status_id', $finals))
            ->when($list->filter('status'), fn ($w, $k) => $w->where('status_id', OrderStatus::idFor($k)))
            ->when($list->filter('channel'), fn ($w, $c) => $w->where('channel', $c))
            ->when($list->filter('owner'), fn ($w, $id) => $w->where('owner_id', $id))
            ->when($list->filter('from'), fn ($w, $d) => $w->where('created_at', '>=', $d.' 00:00:00'))
            ->when($list->filter('to'), fn ($w, $d) => $w->where('created_at', '<=', $d.' 23:59:59'))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('order_no', strtoupper($q))
                ->when(strlen($digits) >= 4, fn ($s) => $s->orWhere('ship_phone', 'like', (str_starts_with($digits, '0') ? $digits : '0'.$digits).'%')->orWhere('order_no', 'IQ'.$digits))
                ->orWhere('ship_name', 'like', $q.'%')))
            ->tap(fn ($w) => $list->applySort($w))
            ->simplePaginate($list->perPage)
            ->withQueryString();

        $counts = [
            'take' => Order::visibleTo($user)->whereNull('owner_id')->whereIn('status_id', $working)->count(),
            'mine' => Order::where('owner_id', $user->id)->whereNotIn('status_id', $finals)->count(),
        ];

        return view('orders.index', [
            'orders' => $orders,
            'list' => $list,
            'tab' => $tab,
            'counts' => $counts,
            'statuses' => OrderStatus::map(),
            'ownerOptions' => $user->permissionScope('orders.view') === 'all' ? User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
        ]);
    }

    public function create(): View
    {
        return view('orders.create', [
            'zones' => DeliveryZone::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all(),
            'methods' => PaymentMethod::where('is_active', true)->get(['id', 'name', 'requires_trx_id']),
            'districts' => config('bd.districts'),
            'discountLimit' => (float) settings('orders.discount_limit'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['messenger', 'whatsapp', 'phone', 'b2b'])],
            'phone' => ['required', 'string', 'max:20'],
            'alt_phone' => ['nullable', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:150'],
            'customer_address_id' => ['nullable', 'integer'],
            'address_line' => ['nullable', 'required_without:customer_address_id', 'string', 'max:500'],
            'district' => ['nullable', 'string', 'max:60'],
            'thana' => ['nullable', 'string', 'max:80'],
            'zone_id' => ['nullable', Rule::exists('delivery_zones', 'id')],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'items.*.line_discount' => ['nullable', 'numeric', 'min:0'],
            'order_discount' => ['nullable', 'numeric', 'min:0'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'advance.method_id' => ['nullable', 'integer'],
            'advance.amount' => ['nullable', 'numeric', 'min:0'],
            'advance.transaction_id' => ['nullable', 'string', 'max:100'],
            'advance.sender_number' => ['nullable', 'string', 'max:20'],
        ]);

        $order = $this->orders->create($data, $request->user());

        return redirect()->route('orders.show', $order)->with('success', __('Order :no created.', ['no' => $order->order_no]));
    }

    public function show(Order $order, Request $request): View
    {
        $user = $request->user();
        abort_unless(Order::visibleTo($user)->whereKey($order->id)->exists(), 403);

        $order->load(['items', 'owner:id,name', 'zone:id,name', 'customer:id,name,orders_count,delivered_count,returned_count,risk_level', 'holdReason:id,label_en']);
        $notes = DB::table('order_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.user_id')
            ->where('n.order_id', $order->id)->orderByDesc('n.id')->limit(200)
            ->get(['n.id', 'n.note_type', 'n.body', 'n.created_at', 'n.status_at_time_id', 'u.name as user']);
        $payments = DB::table('order_payments as p')->join('payment_methods as m', 'm.id', '=', 'p.method_id')
            ->where('p.order_id', $order->id)->orderBy('p.id')
            ->get(['p.id', 'p.payment_type', 'p.amount', 'p.transaction_id', 'p.status', 'p.received_at', 'm.name as method']);

        return view('orders.show', [
            'order' => $order,
            'notes' => $notes,
            'payments' => $payments,
            'statuses' => OrderStatus::map(),
            'targets' => $this->machine->allowedTargets($order, $user),
            'reasons' => ['cancel' => StatusReason::options('cancel'), 'hold' => StatusReason::options('hold'), 'status' => StatusReason::options('status'), 'return' => StatusReason::options('return'), 'reassign' => StatusReason::options('reassign')],
            'canClaim' => $order->owner_id === null && in_array($order->status_id, OrderStatus::idsFor(['new', 'record_verified']), true) && $user->can('orders.edit'),
            'staffOptions' => $user->can('orders.reassign') ? User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
        ]);
    }

    public function claim(Order $order, Request $request): RedirectResponse
    {
        $this->orders->claim($order, $request->user());

        return back()->with('success', __('Order :no is yours now.', ['no' => $order->order_no]));
    }

    public function transition(Order $order, Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(Order::visibleTo($user)->whereKey($order->id)->exists(), 403);
        // Working an order you do not own is not allowed (except for those who see all orders).
        abort_if($order->owner_id !== $user->id && $user->permissionScope('orders.view') !== 'all', 403);

        $data = $request->validate([
            'to' => ['required', 'string'],
            'reason_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'lock_version' => ['required', 'integer'],
            'hold_expected_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $this->machine->transition($order, $data['to'], $user, 'user', $data['reason_id'] ?? null, $data['note'] ?? null, (int) $data['lock_version']);
        if ($data['to'] === 'hold' && ! empty($data['hold_expected_date'])) {
            $order->forceFill(['hold_expected_date' => $data['hold_expected_date']])->save();
        }

        return back()->with('success', __('Order :no is now :s.', ['no' => $order->order_no, 's' => OrderStatus::map()[$order->status_id]['name']]));
    }

    public function note(Order $order, Request $request): RedirectResponse
    {
        abort_unless(Order::visibleTo($request->user())->whereKey($order->id)->exists(), 403);
        $data = $request->validate(['type' => ['required', Rule::in(['manual', 'call', 'chat'])], 'body' => ['required', 'string', 'max:2000']]);
        $this->orders->note($order, $data['type'], $data['body'], $request->user());

        return back()->with('success', __('Note added.'));
    }

    public function reassign(Order $order, Request $request): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', Rule::exists('users', 'id')->where('is_active', true)], 'reason_id' => ['required', Rule::exists('status_reasons', 'id')->where('reason_type', 'reassign')]]);
        $this->orders->reassign($order, $request->user(), User::findOrFail($data['user_id']), (int) $data['reason_id']);

        return back()->with('success', __('Owner changed.'));
    }

    public function verifyPayment(Order $order, int $payment, Request $request): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        $this->orders->verifyPayment($order, $payment, $data['decision'] === 'approve', $request->user());

        return back()->with('success', __('Payment updated.'));
    }

    /** Live delivery-charge preview for the order form (the server recomputes on save). */
    public function deliveryCharge(Request $request, DeliveryCharges $charges): JsonResponse
    {
        return response()->json(['charge' => $charges->for($request->integer('zone_id') ?: null, $request->integer('weight_g'), (float) $request->query('total', 0))]);
    }
}
