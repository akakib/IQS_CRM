<?php

namespace App\Http\Controllers;

use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\StatusReason;
use App\Models\User;
use App\Services\Orders\DeliveryCharges;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\VerificationEngine;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use App\Support\Lists\ListState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'channel' => array_keys(Order::CHANNELS),
            'moderator' => 'int',
            'from' => 'date',
            'to' => 'date',
        ], 'desc');
        $tab = $list->filter('tab') ?? 'all';
        $finals = array_keys(array_filter(OrderStatus::map(), fn ($s) => $s['final']));
        $q = trim($list->search);
        $digits = preg_replace('/\D/', '', $q);

        // Large, fast-growing list: simple pagination (no COUNT), indexed filters.
        $orders = Order::query()->visibleTo($user)
            ->select(['id', 'order_no', 'channel', 'status_id', 'moderator_id', 'ship_name', 'ship_phone', 'grand_total', 'cod_amount',
                'is_duplicate_flag', 'packed_version', 'current_version', 'edited_after_pack', 'created_at'])
            ->with('moderator:id,name')
            ->when($tab === 'take', fn ($w) => app(\App\Services\Orders\DeskService::class)->whereWaiting($w))
            ->when($tab === 'mine', fn ($w) => $w->where('moderator_id', $user->id)->whereNotIn('status_id', $finals))
            ->when($list->filter('status'), fn ($w, $k) => $w->where('status_id', OrderStatus::idFor($k)))
            ->when($list->filter('channel'), fn ($w, $c) => $w->where('channel', $c))
            ->when($list->filter('moderator'), fn ($w, $id) => $w->where('moderator_id', $id))
            ->when($list->filter('from'), fn ($w, $d) => $w->where('created_at', '>=', $d.' 00:00:00'))
            ->when($list->filter('to'), fn ($w, $d) => $w->where('created_at', '<=', $d.' 23:59:59'))
            ->when($q !== '', function ($w) use ($q, $digits) {
                // CN or tracking code (also of a deleted older parcel): looked up first, so the main query is
                // indexed columns OR'ed together (not a subquery evaluated per row: that scanned every order).
                $byCn = DB::table('shipments')->where('consignment_id', $q)->orWhere('tracking_code', strtoupper($q))->limit(20)->pluck('order_id')->all();
                $w->where(fn ($s) => $s
                    ->where('order_no', strtoupper($q))
                    ->when(strlen($digits) >= 4, fn ($s) => $s->orWhere('ship_phone', 'like', (str_starts_with($digits, '0') ? $digits : '0'.$digits).'%')->orWhere('order_no', 'IQ'.$digits))
                    ->orWhere('ship_name', 'like', $q.'%')
                    ->when($byCn, fn ($s) => $s->orWhereIn('id', $byCn)));
            })
            ->tap(fn ($w) => $list->applySort($w))
            ->simplePaginate($list->perPage)
            ->withQueryString();

        $counts = [
            'take' => app(\App\Services\Orders\DeskService::class)->whereWaiting(Order::visibleTo($user))->count(),
            'mine' => Order::where('moderator_id', $user->id)->whereNotIn('status_id', $finals)->count(),
        ];

        return view('orders.index', [
            'orders' => $orders,
            'list' => $list,
            'tab' => $tab,
            'counts' => $counts,
            'statuses' => OrderStatus::map(),
            'moderatorOptions' => $user->permissionScope('orders.view') === 'all' ? User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
        ]);
    }

    public function create(Request $request): View
    {
        // From the Chat window: the order starts on that chat's channel (only one of the person's own).
        $chatChannels = app(\App\Services\Work\ChatService::class)->channelsFor($request->user());
        $chatChannel = $chatChannels->firstWhere('id', $request->integer('chat_channel'));

        return view('orders.create', [
            'chatChannels' => $chatChannels,
            'chatChannel' => $chatChannel,
            'zones' => DeliveryZone::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all(),
            'methods' => PaymentMethod::where('is_active', true)->get(['id', 'name', 'requires_trx_id']),
            'districts' => config('bd.districts'),
            'discountLimit' => (float) settings('orders.discount_limit'),
            'districts' => config('bd.districts'),
        ]);
    }

    public function store(Request $request): RedirectResponse|\Illuminate\Http\Response
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(array_keys(Order::manualChannels()))],
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
            'chat_channel_id' => ['nullable', 'integer'],
        ]);
        if (! empty($data['chat_channel_id'])) {
            $chat = app(\App\Services\Work\ChatService::class)->channelsFor($request->user())->firstWhere('id', (int) $data['chat_channel_id']);
            if (! $chat) {
                throw ValidationException::withMessages(['chat_channel_id' => __('This chat channel is not yours.')]);
            }
        }

        $order = $this->orders->create($data, $request->user());
        app(VerificationEngine::class)->run($order);
        $this->confirmTakenOrder($order->refresh(), $request->user());

        $message = __('Order :no created.', ['no' => $order->order_no]);
        // Made from the New order popup (Communication): the page behind it goes to Order management.
        if ($request->boolean('embed')) {
            session()->flash('success', $message);

            return response()->view('orders.create-done', ['order' => $order]);
        }

        return redirect()->route('orders.show', $order)->with('success', $message);
    }

    public function show(Order $order, Request $request): View
    {
        $user = $request->user();
        abort_unless(Order::visibleTo($user)->whereKey($order->id)->exists(), 403);

        $order->load(['items', 'moderator:id,name', 'zone:id,name', 'customer:id,name,orders_count,delivered_count,returned_count,risk_level', 'holdReason:id,label_en']);
        $notes = DB::table('order_notes as n')->leftJoin('users as u', 'u.id', '=', 'n.user_id')
            ->where('n.order_id', $order->id)->orderByDesc('n.id')->limit(200)
            ->get(['n.id', 'n.note_type', 'n.body', 'n.created_at', 'n.status_at_time_id', 'n.meta', 'u.name as user']);
        $payments = DB::table('order_payments as p')->join('payment_methods as m', 'm.id', '=', 'p.method_id')
            ->where('p.order_id', $order->id)->orderBy('p.id')
            ->get(['p.id', 'p.payment_type', 'p.amount', 'p.transaction_id', 'p.status', 'p.counts_now', 'p.received_at', 'm.name as method']);

        $amendments = DB::table('order_amendments as a')->join('users as u', 'u.id', '=', 'a.requested_by')
            ->join('status_reasons as r', 'r.id', '=', 'a.reason_id')
            ->where('a.order_id', $order->id)->where('a.approval_status', 'pending')
            ->get(['a.id', 'a.changes', 'a.amount_diff', 'a.created_at', 'u.name as by', 'r.label_en as reason']);

        $verification = DB::table('verification_runs as v')->leftJoin('verification_rules as r', 'r.id', '=', 'v.matched_rule_id')
            ->where('v.order_id', $order->id)->orderByDesc('v.id')->first(['v.outcome', 'v.inputs_snapshot', 'v.created_at', 'r.name as rule']);

        return view('orders.show', [
            'order' => $order,
            'verification' => $verification,
            'amendments' => $amendments,
            'canEdit' => OrderStatus::map()[$order->status_id]['edit_policy'] !== 'locked'
                && ($order->moderator_id === $user->id || $user->permissionScope('orders.view') === 'all') && $user->can('orders.edit'),
            'notes' => $notes,
            'payments' => $payments,
            'paymentMethods' => DB::table('payment_methods')->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all(),
            'statuses' => OrderStatus::map(),
            'targets' => $this->machine->allowedTargets($order, $user),
            'reasons' => ['cancel' => StatusReason::options('cancel'), 'hold' => StatusReason::options('hold'), 'status' => StatusReason::options('status'), 'return' => StatusReason::options('return'), 'reassign' => StatusReason::options('reassign')],
            'staffOptions' => $user->can('orders.reassign') ? User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
        ]);
    }

    public function transition(Order $order, Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(Order::visibleTo($user)->whereKey($order->id)->exists(), 403);
        // Working an order you do not own is not allowed (except for those who see all orders).
        abort_if($order->moderator_id !== $user->id && $user->permissionScope('orders.view') !== 'all', 403);

        $data = $request->validate([
            'to' => ['required', 'string'],
            'reason_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'lock_version' => ['required', 'integer'],
            'hold_expected_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        // Proof of a real call: a website order is confirmed by hand only after a logged call.
        if ($data['to'] === 'confirmed' && $order->channel === 'web'
            && ! DB::table('order_notes')->where('order_id', $order->id)->where('note_type', 'call')->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => __('Log the call first (Order management, or add a Call note below).')]);
        }

        if ($data['to'] === 'hold') {
            app(\App\Services\Orders\DeskService::class)->checkHold($user, $order, $data['hold_expected_date'] ?? null);
        }
        $this->machine->transition($order, $data['to'], $user, 'user', $data['reason_id'] ?? null, $data['note'] ?? null, (int) $data['lock_version']);
        if ($data['to'] === 'hold' && ! empty($data['hold_expected_date'])) {
            $order->forceFill(['hold_expected_date' => $data['hold_expected_date']])->save();
        }

        return back()->with('success', __('Order :no is now :s.', ['no' => $order->order_no, 's' => OrderStatus::map()[$order->status_id]['name']]));
    }

    /** Booked by mistake: back to Call; the parcel is deleted at the courier by hand. */
    public function takeBack(Order $order, Request $request, \App\Services\Orders\DeskService $desk): RedirectResponse
    {
        $data = $request->validate(['why' => ['required', 'string', 'min:5', 'max:300']]);
        $desk->takeBack($order, $request->user(), $data['why']);

        return back()->with('success', __(':no is back in Call. Delete its parcel at the courier, then press Deleted.', ['no' => $order->order_no]));
    }

    /** The COD was changed by hand in the courier's panel. */
    public function codUpdated(Order $order, Request $request): RedirectResponse
    {
        $this->authorizeWork($order, $request->user());
        $this->orders->markCodUpdated($order, $request->user());

        return back()->with('success', __('COD confirmed at the courier. :no can be handed over.', ['no' => $order->order_no]));
    }

    /** The booking of a cancelled order was deleted by hand in the courier's panel. */
    public function courierCancelled(Order $order, Request $request): RedirectResponse
    {
        $this->authorizeWork($order, $request->user());
        $this->orders->markCourierCancelled($order, $request->user());

        return back()->with('success', __('Parcel :no is cancelled at the courier too.', ['no' => $order->order_no]));
    }

    /** Screens with this order open check in here every few seconds (who else is on it, who is editing). */
    public function presence(Order $order, Request $request, \App\Services\Orders\OrderPresence $presence): JsonResponse
    {
        abort_unless(Order::visibleTo($request->user())->whereKey($order->id)->exists(), 403);
        $data = $request->validate(['mode' => ['required', Rule::in(['view', 'edit', 'leave'])], 'take_over' => ['nullable', 'boolean']]);

        return response()->json($presence->checkIn($order, $request->user(), $data['mode'],
            ($data['take_over'] ?? false) && $request->user()->can('orders.reassign')));
    }

    public function note(Order $order, Request $request): RedirectResponse|JsonResponse
    {
        abort_unless(Order::visibleTo($request->user())->whereKey($order->id)->exists(), 403);
        $data = $request->validate(['type' => ['required', Rule::in(['manual', 'call', 'chat'])], 'body' => ['required', 'string', 'max:2000']]);
        $this->orders->note($order, $data['type'], $data['body'], $request->user());

        // Added from a page that stays open (Order management): send back the entry so it can slide into the history.
        if ($request->expectsJson()) {
            $entry = (object) ['note_type' => $data['type'], 'body' => $data['body'], 'user' => $request->user()->name, 'created_at' => now()];

            return response()->json(['html' => view('components.timeline', ['entries' => [$entry], 'attributes' => new \Illuminate\View\ComponentAttributeBag])->render()]);
        }

        return back()->with('success', __('Note added.'));
    }

    public function reassign(Order $order, Request $request): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', Rule::exists('users', 'id')->where('is_active', true)], 'reason_id' => ['required', Rule::exists('status_reasons', 'id')->where('reason_type', 'reassign')]]);
        $this->orders->reassign($order, $request->user(), User::findOrFail($data['user_id']), (int) $data['reason_id']);

        return back()->with('success', __('Reassigned.'));
    }

    public function verifyPayment(Order $order, int $payment, Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('payments.verify') || $request->user()->can('orders.approve'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        $this->orders->verifyPayment($order, $payment, $data['decision'] === 'approve', $request->user());

        return back()->with('success', __('Payment updated.'));
    }

    /** Money the customer sent after the order was placed (bKash and so on). */
    public function storePayment(Order $order, Request $request): RedirectResponse
    {
        $this->authorizeWork($order, $request->user());
        $data = $request->validate([
            'advance.method_id' => ['required', 'integer'],
            'advance.amount' => ['required', 'numeric', 'min:1'],
            'advance.transaction_id' => ['nullable', 'string', 'max:100'],
            'advance.sender_number' => ['nullable', 'string', 'max:20'],
        ]);
        // From the advance box on a website order: the moderator called and the customer sent it, so the call is logged
        // and (once the advance counts) the order goes on to booking instead of a second call.
        if ($request->boolean('called') && $order->channel === 'web' && $order->advance_required) {
            $this->orders->note($order, 'call', trim(__('Called: customer sent the advance').($request->input('note') ? ' · '.$request->input('note') : '')), $request->user(), ['outcome' => 'confirmed']);
        }
        $this->orders->addPayment($order, $data['advance'] + ['payment_type' => 'advance'], $request->user());

        return back()->with('success', __('Payment saved. It is checked on the Payments page.'));
    }

    public function advanceWillPayBy(Order $order, Request $request): RedirectResponse
    {
        $this->authorizeWork($order, $request->user());
        $data = $request->validate(['date' => ['required', 'date', 'after_or_equal:today']]);
        $this->orders->advanceWillPayBy($order, $request->user(), $data['date']);

        return back()->with('success', __('Noted. You are reminded on :d.', ['d' => \Illuminate\Support\Carbon::parse($data['date'])->format('d M')]));
    }

    /** "Ask admin: process without advance". */
    public function askWaiver(Order $order, Request $request): RedirectResponse
    {
        $this->authorizeWork($order, $request->user());
        $data = $request->validate(['why' => ['required', 'string', 'min:5', 'max:300']]);
        $this->orders->requestAdvanceWaiver($order, $request->user(), $data['why']);

        return back()->with('success', __('Asked. You get a notice when an admin decides.'));
    }

    public function decideWaiver(Order $order, Request $request): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:allow,refuse']]);
        $this->orders->decideAdvanceWaiver($order, $request->user(), $data['decision'] === 'allow');

        return back()->with('success', $data['decision'] === 'allow' ? __(':no goes on without advance.', ['no' => $order->order_no]) : __(':no keeps waiting for the advance.', ['no' => $order->order_no]));
    }

    public function edit(Order $order, Request $request): View
    {
        $this->authorizeWork($order, $request->user());
        abort_if(OrderStatus::map()[$order->status_id]['edit_policy'] === 'locked', 403, __('This order can no longer be edited.'));
        $order->load('items.variant:id,unit,weight_g');

        return view('orders.edit', [
            'order' => $order,
            'items' => $this->editLines($order, $request->old('items')),
            'zones' => DeliveryZone::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all(),
            'reasons' => StatusReason::options('amendment'),
            'policy' => OrderStatus::map()[$order->status_id]['edit_policy'],
            'orderDiscount' => app(\App\Services\Orders\OrderEditor::class)->orderDiscount($order),
            'paid' => (float) DB::table('order_payments')->where('order_id', $order->id)->where('status', 'verified')->whereIn('payment_type', ['advance', 'adjustment'])->sum('amount'),
            'discountLimit' => (float) settings('orders.discount_limit'),
        ]);
    }

    /**
     * Lines for the edit form. After a save that came back with an error, the
     * lines as the person left them (added, removed, changed), not the saved
     * ones, so nothing they did is lost. Lines already on the order keep the
     * price they were sold at; new ones show today's online price.
     */
    private function editLines(Order $order, ?array $typed): \Illuminate\Support\Collection
    {
        $sold = $order->items->keyBy('variant_id');
        $line = fn ($i) => [
            'variant_id' => $i->variant_id, 'label' => $i->name_snapshot, 'sub' => $i->sku_snapshot, 'price' => (float) $i->unit_price,
            'unit' => $i->unit, 'weight_g' => (int) ($i->variant?->weight_g ?? 0), 'qty' => (float) $i->qty, 'line_discount' => (float) $i->line_discount,
        ];
        if (! is_array($typed) || $typed === []) {
            return $order->items->map($line)->values();
        }

        $ids = collect($typed)->pluck('variant_id')->map(fn ($id) => (int) $id)->filter()->unique();
        $online = DB::table('price_lists')->where('system_key', 'online')->value('id');
        $variants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('variant_prices as vp', fn ($j) => $j->on('vp.variant_id', '=', 'v.id')->where('vp.price_list_id', $online))
            ->whereIn('v.id', $ids)
            ->get(['v.id', 'v.sku', 'v.name as variant', 'v.unit', 'v.weight_g', 'p.name as product', 'vp.regular_price', 'vp.sale_price', 'vp.sale_starts_at', 'vp.sale_ends_at'])
            ->keyBy('id');

        return collect($typed)->map(function ($t) use ($sold, $variants, $line) {
            $id = (int) ($t['variant_id'] ?? 0);
            if (isset($sold[$id])) {
                $row = $line($sold[$id]);
            } elseif ($v = $variants[$id] ?? null) {
                $row = [
                    'variant_id' => $id, 'label' => $v->product.' · '.$v->variant, 'sub' => $v->sku,
                    'price' => $v->regular_price === null ? 0.0 : (float) (new \App\Models\VariantPrice((array) $v))->effective(),
                    'unit' => $v->unit, 'weight_g' => (int) $v->weight_g, 'qty' => 1.0, 'line_discount' => 0.0,
                ];
            } else {
                return null;
            }

            return ['qty' => (float) ($t['qty'] ?? $row['qty']), 'line_discount' => (float) ($t['line_discount'] ?? 0)] + $row;
        })->filter()->values();
    }

    public function amend(Order $order, Request $request, OrderEditor $editor): RedirectResponse|Response
    {
        $this->authorizeWork($order, $request->user());
        if ($other = app(\App\Services\Orders\OrderPresence::class)->editorOtherThan($order->id, $request->user()->id)) {
            throw ValidationException::withMessages(['order' => __(':n is editing this order. Wait until they finish.', ['n' => $other['name']])]);
        }
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'reason_id' => ['required', Rule::exists('status_reasons', 'id')->where('reason_type', 'amendment')],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.line_discount' => ['nullable', 'numeric', 'min:0'],
            'order_discount' => ['nullable', 'numeric', 'min:0'],
            'ship_name' => ['required', 'string', 'max:150'],
            'ship_phone' => ['required', 'string', 'max:20'],
            'ship_alt_phone' => ['nullable', 'string', 'max:20'],
            'ship_address' => ['required', 'string', 'max:500'],
            'ship_district' => ['nullable', 'string', 'max:60'],
            'ship_thana' => ['nullable', 'string', 'max:80'],
            'zone_id' => ['nullable', Rule::exists('delivery_zones', 'id')],
        ]);

        $result = $editor->request($order, $data, (int) $data['reason_id'], $request->user(), (int) $data['lock_version']);

        $message = $result['applied'] ? __('Order updated.') : __('Change sent to a manager for approval.');
        // Saved from the edit popup: the page behind it reloads and shows the message.
        if ($request->boolean('embed')) {
            session()->flash('success', $message);

            return response()->view('orders.edit-done');
        }

        return redirect()->route('orders.show', $order)->with('success', $message);
    }

    public function decideAmendment(Order $order, int $amendment, Request $request, OrderEditor $editor): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        $editor->decide($order, $amendment, $data['decision'] === 'approve', $request->user());

        return back()->with('success', $data['decision'] === 'approve' ? __('Change approved and applied.') : __('Change rejected.'));
    }

    /**
     * An order taken in a chat or a call was confirmed in that talk: straight to Confirmed (then booking
     * and packaging), no second call. The checks still ran first: an advance hold (bad courier record) stays.
     */
    private function confirmTakenOrder(Order $order, User $user): void
    {
        $key = OrderStatus::map()[$order->status_id]['key'];
        if ($order->channel === 'web' || ! in_array($key, ['new', 'record_verified'], true)) {
            return;
        }
        if ($key === 'new') {
            $order = $this->machine->transition($order, 'record_verified', $user, 'user', null, __('Taken in a chat or call'));
        }
        $this->machine->transition($order, 'confirmed', $user, 'user', null, __('Confirmed in the chat or call it was taken in'));
    }

    private function authorizeWork(Order $order, User $user): void
    {
        abort_unless(Order::visibleTo($user)->whereKey($order->id)->exists(), 403);
        abort_if($order->moderator_id !== $user->id && $user->permissionScope('orders.view') !== 'all', 403);
    }

    /** Live delivery-charge preview for the order form (the server recomputes on save). */
    public function deliveryCharge(Request $request, DeliveryCharges $charges): JsonResponse
    {
        return response()->json(['charge' => $charges->for($request->integer('zone_id') ?: null, $request->integer('weight_g'), (float) $request->query('total', 0))]);
    }

    /**
     * Admins and managers: bring website orders now instead of waiting for the
     * webhook or the 10-minute sweep. The last 24 hours, through the same
     * intake, so nothing comes in twice and nothing here is touched without news.
     */
    public function syncWebsite(Request $request, \App\Services\Orders\WooOrderSweep $sweep): RedirectResponse
    {
        if (! \Illuminate\Support\Facades\Cache::add('woo:sync:manual', 1, 30)) {
            return back()->with('error', __('Synced a moment ago. Try again in half a minute.'));
        }
        $r = $sweep->sync(24);
        app(\App\Services\ActivityLogger::class)->log('orders.website_sync', null, null, ['new' => $r['new'], 'updated' => $r['updated'], 'checked' => $r['checked']]);

        return match (true) {
            $r['error'] !== null => back()->with('error', $r['error']),
            $r['busy'] => back()->with('success', __('A sync is already running. New orders appear in a moment.')),
            $r['new'] + $r['updated'] === 0 => back()->with('success', __('Nothing missing: :n website orders of the last 24 hours checked.', ['n' => $r['checked']])),
            default => back()->with('success', trim(
                trans_choice('{0}|{1} 1 new order brought in.|[2,*] :count new orders brought in.', $r['new']).' '.
                trans_choice('{0}|{1} 1 order updated.|[2,*] :count orders updated.', $r['updated']))),
        };
    }
}
