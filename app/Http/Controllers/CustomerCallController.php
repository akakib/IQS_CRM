<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
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
    public const OUTCOMES = ['answered' => 'Answered', 'no_answer' => 'No answer', 'busy' => 'Busy or switched off', 'wrong' => 'Wrong number'];

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
            'minutes' => ['nullable', 'integer', 'min:0', 'max:300'], 'seconds' => ['nullable', 'integer', 'min:0', 'max:59'],
            'recording_url' => ['nullable', 'url:http,https', 'max:500'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $duration = ($data['minutes'] ?? null) !== null || ($data['seconds'] ?? null) !== null ? (int) ($data['minutes'] ?? 0) * 60 + (int) ($data['seconds'] ?? 0) : null;
        DB::table('customer_calls')->where('id', $call)->update([
            'outcome' => $data['outcome'], 'duration_seconds' => $duration, 'recording_url' => $data['recording_url'] ?? null, 'note' => $data['note'] ?? null,
        ]);
        if ($row->order_id && ($order = Order::find($row->order_id))) {
            $line = __('Called').($duration !== null ? ' ('.intdiv($duration, 60).':'.str_pad((string) ($duration % 60), 2, '0', STR_PAD_LEFT).')' : '')
                .': '.__(self::OUTCOMES[$data['outcome']]).(! empty($data['note']) ? ' · '.$data['note'] : '');
            $this->orders->note($order, 'call', $line, $request->user(),
                ['customer_call_id' => $call, 'recording_url' => $data['recording_url'] ?? null, 'call_outcome' => $data['outcome']]);
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

        return response()->json([
            'phone' => $phone ?? $orders->first()?->ship_phone,
            'name' => $orders->first()?->ship_name,
            'orders' => $orders->map(fn ($o) => [
                'id' => $o->id, 'order_no' => $o->order_no, 'status' => __(OrderStatus::map()[$o->status_id]['name']),
                'total' => (float) $o->grand_total, 'when' => $o->created_at->format('d M'),
            ])->values(),
        ]);
    }

    /** Save a call that came in. */
    public function incoming(Request $request): JsonResponse
    {
        $this->allowIncoming($request);
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'], 'order_id' => ['nullable', 'integer'],
            'reason' => ['required', Rule::in(array_keys(self::REASONS_IN))],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:300'], 'seconds' => ['nullable', 'integer', 'min:0', 'max:59'],
            'recording_url' => ['nullable', 'url:http,https', 'max:500'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            throw \Illuminate\Validation\ValidationException::withMessages(['phone' => __('Type a full mobile number.')]);
        }
        $order = ! empty($data['order_id']) ? Order::visibleTo($request->user())->find($data['order_id']) : null;
        $duration = ($data['minutes'] ?? null) !== null || ($data['seconds'] ?? null) !== null ? (int) ($data['minutes'] ?? 0) * 60 + (int) ($data['seconds'] ?? 0) : null;
        $id = DB::table('customer_calls')->insertGetId([
            'order_id' => $order?->id, 'phone' => $phone, 'direction' => 'in', 'user_id' => $request->user()->id, 'started_at' => now(),
            'outcome' => $data['reason'], 'duration_seconds' => $duration, 'recording_url' => $data['recording_url'] ?? null, 'note' => $data['note'] ?? null,
        ]);
        if ($order) {
            $line = __('Customer called').($duration !== null ? ' ('.intdiv($duration, 60).':'.str_pad((string) ($duration % 60), 2, '0', STR_PAD_LEFT).')' : '')
                .': '.__(self::REASONS_IN[$data['reason']]).(! empty($data['note']) ? ' · '.$data['note'] : '');
            $this->orders->note($order, 'call', $line, $request->user(), ['customer_call_id' => $id, 'recording_url' => $data['recording_url'] ?? null, 'direction' => 'in']);
        }
        app(ChatService::class)->start($request->user()); // talking to a customer counts as work

        return response()->json([
            'message' => __('Call saved.'),
            'today' => DB::table('customer_calls')->where('user_id', $request->user()->id)->where('direction', 'in')->where('started_at', '>=', today())->count(),
            'new_order_url' => $data['reason'] === 'new_order' && $request->user()->can('orders.create') ? route('orders.create', ['channel' => 'phone', 'phone' => $phone, 'embed' => 1]) : null,
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
                SUM(CASE WHEN c.direction = 'in' THEN 1 ELSE 0 END) as in_n, SUM(COALESCE(c.duration_seconds, 0)) as seconds, SUM(CASE WHEN c.recording_url IS NOT NULL THEN 1 ELSE 0 END) as recorded")
            ->orderByRaw('COUNT(*) DESC')->get();
        $calls = DB::table('customer_calls as c')->join('users as u', 'u.id', '=', 'c.user_id')->leftJoin('orders as o', 'o.id', '=', 'c.order_id')
            ->whereBetween('c.started_at', $range)->orderByDesc('c.started_at')
            ->select('c.*', 'u.name as person', 'o.order_no')->paginate(25)->withQueryString();

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
