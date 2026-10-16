<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Calls\CallProof;
use App\Services\Orders\OrderService;
use App\Services\Work\ChatService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Calls with customers. Out: started from the order's number (tap to call,
 * copy or QR); after the call, how it went, how long, the recording's Drive
 * link. In: a customer called; logged in Communication (Customer calls), on
 * one of their orders or on the number alone. Both go into the order's
 * history; the Communication report lists them.
 */
class CustomerCallController extends Controller
{
    public const OUTCOMES = ['answered' => 'Talked', 'no_answer' => 'No answer', 'busy' => 'Busy or off', 'wrong' => 'Wrong number'];

    public const REASONS_IN = ['new_order' => 'Wants to order', 'question' => 'About an order', 'complaint' => 'Complaint', 'other' => 'Other'];

    public function __construct(private OrderService $orders) {}

    /** The number was tapped, copied or shown as a QR: a call is starting. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['order_id' => ['required', 'integer']]);
        $order = Order::visibleTo($request->user())->findOrFail($data['order_id']);
        // A second tap within two minutes is the same call.
        $id = DB::table('customer_calls')->where('order_id', $order->id)->where('user_id', $request->user()->id)->where('direction', 'out')
            ->whereNull('outcome')->where('started_at', '>=', now()->subMinutes(2))->value('id')
            ?? DB::table('customer_calls')->insertGetId(['order_id' => $order->id, 'phone' => $order->ship_phone, 'direction' => 'out', 'user_id' => $request->user()->id, 'started_at' => now()]);

        return response()->json(['id' => $id]);
    }

    /** After the call. */
    public function finish(Request $request, int $call): JsonResponse
    {
        $row = DB::table('customer_calls')->where('id', $call)->where('user_id', $request->user()->id)->first() ?? abort(404);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(array_keys(self::OUTCOMES))],
            'satisfaction' => ['nullable', 'integer', Rule::in(array_keys(\App\Services\Calls\CallListService::SATISFACTION))],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:300'], 'seconds' => ['nullable', 'integer', 'min:0', 'max:59'],
            'recording_url' => ['nullable', 'url:http,https', 'max:500'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $duration = ($data['minutes'] ?? null) !== null || ($data['seconds'] ?? null) !== null ? (int) ($data['minutes'] ?? 0) * 60 + (int) ($data['seconds'] ?? 0) : null;
        $proof = app(CallProof::class)->take($request, [$row->phone], 'call-proofs/order', (string) $call);
        DB::table('customer_calls')->where('id', $call)->update([
            'outcome' => $data['outcome'], 'duration_seconds' => $duration, 'recording_url' => $data['recording_url'] ?? null, 'note' => $data['note'] ?? null,
            'satisfaction' => $data['outcome'] === 'answered' ? ($data['satisfaction'] ?? null) : null,
        ] + $proof);
        if ($row->order_id && ($order = Order::find($row->order_id))) {
            $line = __('Called').($duration !== null ? ' ('.intdiv($duration, 60).':'.str_pad((string) ($duration % 60), 2, '0', STR_PAD_LEFT).')' : '')
                .': '.__(self::OUTCOMES[$data['outcome']]).(! empty($data['note']) ? ' · '.$data['note'] : '');
            $this->orders->note($order, 'call', $line, $request->user(),
                ['customer_call_id' => $call, 'recording_url' => $data['recording_url'] ?? null, 'call_outcome' => $data['outcome'], 'proof_call' => $proof ? $call : null]);
        }

        return response()->json(['message' => __('Call saved.')]);
    }

    // ── Customer calls in (Communication) ──────────────────────

    /** The number (or order no) a caller gave: their recent orders this person may see. */
    public function find(Request $request): JsonResponse
    {
        $this->allowIncoming($request);
        $q = trim((string) $request->query('q'));
        $phone = Phone::normalize($q);
        $orders = Order::visibleTo($request->user())
            ->where(fn ($w) => $phone ? $w->where('ship_phone', $phone)->orWhere('ship_alt_phone', $phone) : $w->where('order_no', strtoupper($q)))
            ->orderByDesc('id')->limit(5)->get(['id', 'order_no', 'ship_name', 'ship_phone', 'status_id', 'grand_total', 'created_at']);

        $known = ($phone ?? $orders->first()?->ship_phone) ? app(\App\Services\Customers\CustomerService::class)->findByPhone($phone ?? $orders->first()->ship_phone) : null;

        return response()->json([
            'phone' => $phone ?? $orders->first()?->ship_phone,
            'name' => $orders->first()?->ship_name ?? $known?->name,
            'known' => $known !== null || $orders->isNotEmpty(),
            'orders' => $orders->map(fn ($o) => [
                'id' => $o->id, 'order_no' => $o->order_no, 'status' => __(OrderStatus::map()[$o->status_id]['name']),
                'total' => (float) $o->grand_total, 'when' => $o->created_at->format('d M'),
            ])->values(),
        ]);
    }

    /** Who may be calling: the latest orders this person may see, one row a number (8 numbers, however old). */
    public function recent(Request $request): JsonResponse
    {
        $this->allowIncoming($request);
        $orders = Order::visibleTo($request->user())->orderByDesc('id')->limit(40)
            ->get(['id', 'order_no', 'ship_name', 'ship_phone', 'created_at'])->unique('ship_phone')->take(8);

        return response()->json(['people' => $orders->map(fn ($o) => [
            'phone' => $o->ship_phone, 'name' => $o->ship_name,
            'from' => __('Order :no · :t', ['no' => $o->order_no, 't' => $o->created_at->isToday() ? __('today :t', ['t' => $o->created_at->format('g:i A')])
                : ($o->created_at->isYesterday() ? __('yesterday') : $o->created_at->format('d M'))]),
        ])->values()]);
    }

    /** Save a call from Communication: a customer called (direction in, why) or I called them (direction out, what happened). */
    public function incoming(Request $request): JsonResponse
    {
        $this->allowIncoming($request);
        $out = $request->input('direction') === 'out';
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'], 'order_id' => ['nullable', 'integer'],
            'reason' => [$out ? 'nullable' : 'required', Rule::in(array_keys(self::REASONS_IN))],
            'outcome' => [$out ? 'required' : 'nullable', Rule::in(array_keys(self::OUTCOMES))],
            'satisfaction' => ['nullable', 'integer', Rule::in(array_keys(\App\Services\Calls\CallListService::SATISFACTION))],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:300'], 'seconds' => ['nullable', 'integer', 'min:0', 'max:59'],
            'recording_url' => ['nullable', 'url:http,https', 'max:500'], 'note' => ['nullable', 'string', 'max:500'],
            'name' => ['nullable', 'string', 'max:150'], 'address' => ['nullable', 'string', 'max:500'],
        ]);
        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            throw \Illuminate\Validation\ValidationException::withMessages(['phone' => __('Type a full mobile number.')]);
        }
        // A new caller who gave a name or an address becomes a customer (never a second one for the same number).
        $name = trim((string) ($data['name'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        $people = app(\App\Services\Customers\CustomerService::class);
        if (($name !== '' || $address !== '') && ! $people->findByPhone($phone)) {
            $people->save(new \App\Models\Customer, ['name' => $name ?: $phone, 'primary_phone' => $phone], [],
                $address !== '' ? [['address_line' => $address, 'is_default' => true]] : [], $request->user()->id);
        }
        $order = ! empty($data['order_id']) ? Order::visibleTo($request->user())->find($data['order_id']) : null;
        $duration = ($data['minutes'] ?? null) !== null || ($data['seconds'] ?? null) !== null ? (int) ($data['minutes'] ?? 0) * 60 + (int) ($data['seconds'] ?? 0) : null;
        $proof = app(CallProof::class)->take($request, [$phone], $out ? 'call-proofs/order' : 'call-proofs/in', $phone);
        $outcome = $out ? $data['outcome'] : $data['reason'];
        $id = DB::table('customer_calls')->insertGetId([
            'order_id' => $order?->id, 'phone' => $phone, 'direction' => $out ? 'out' : 'in', 'user_id' => $request->user()->id, 'started_at' => now(),
            'outcome' => $outcome, 'duration_seconds' => $duration, 'recording_url' => $data['recording_url'] ?? null, 'note' => $data['note'] ?? null,
            'satisfaction' => $outcome === 'answered' || ! $out ? ($data['satisfaction'] ?? null) : null,
        ] + $proof);
        if ($order) {
            $line = __($out ? 'Called' : 'Customer called').($duration !== null ? ' ('.intdiv($duration, 60).':'.str_pad((string) ($duration % 60), 2, '0', STR_PAD_LEFT).')' : '')
                .': '.__($out ? self::OUTCOMES[$outcome] : self::REASONS_IN[$outcome]).(! empty($data['note']) ? ' · '.$data['note'] : '');
            $this->orders->note($order, 'call', $line, $request->user(), ['customer_call_id' => $id, 'recording_url' => $data['recording_url'] ?? null, 'direction' => $out ? 'out' : 'in', 'proof_call' => $proof ? $id : null]);
        }
        app(ChatService::class)->start($request->user()); // talking to a customer counts as work

        return response()->json([
            'message' => __('Call saved.'),
            'today' => DB::table('customer_calls')->where('user_id', $request->user()->id)->where('direction', $out ? 'out' : 'in')->where('started_at', '>=', today())->count(),
            'new_order_url' => ! $out && $outcome === 'new_order' && $request->user()->can('orders.create') ? route('orders.create', ['channel' => 'phone', 'phone' => $phone, 'embed' => 1]) : null,
        ]);
    }

    /** Communication report, Customer calls: in and out per person, and the calls with their recordings. */
    public function report(Request $request): View
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = Carbon::parse($data['to'] ?? today())->min(today());
        $from = Carbon::parse($data['from'] ?? $to->copy()->subDays(6));
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $people = DB::table('customer_calls as c')->join('users as u', 'u.id', '=', 'c.user_id')->whereBetween('c.started_at', $range)
            ->groupBy('c.user_id', 'u.name')
            ->selectRaw("u.name, SUM(CASE WHEN c.direction = 'out' THEN 1 ELSE 0 END) as out_n, SUM(CASE WHEN c.direction = 'out' AND c.outcome = 'answered' THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN c.direction = 'in' THEN 1 ELSE 0 END) as in_n, SUM(COALESCE(c.duration_seconds, 0)) as seconds, SUM(CASE WHEN c.recording_url IS NOT NULL OR c.proof_path IS NOT NULL THEN 1 ELSE 0 END) as recorded")
            ->orderByRaw('COUNT(*) DESC')->get();
        $calls = DB::table('customer_calls as c')->join('users as u', 'u.id', '=', 'c.user_id')->leftJoin('orders as o', 'o.id', '=', 'c.order_id')
            ->whereBetween('c.started_at', $range)->orderByDesc('c.started_at')
            ->select('c.*', 'u.name as person', 'o.order_no')->paginate(25)->withQueryString()->fragment('calls');

        return view('reports.customer-calls', [
            'from' => $from, 'to' => $to, 'people' => $people, 'calls' => $calls,
            'labels' => self::OUTCOMES + self::REASONS_IN,
        ]);
    }

    /** Anyone with Communication: a chat channel, the rider line or the hotline. */
    private function allowIncoming(Request $request): void
    {
        $user = $request->user();
        abort_unless(app(ChatService::class)->allFor($user)->isNotEmpty() || $user->can('hotline.view'), 403);
    }
}
