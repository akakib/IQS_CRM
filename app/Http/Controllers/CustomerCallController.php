<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Calls to customers, started from the order's number (tap to call, copy or
 * QR). After the call: how it went, how long, and the recording's Drive link.
 * Goes into the order's history and the person's Activity.
 */
class CustomerCallController extends Controller
{
    public const OUTCOMES = ['answered' => 'Answered', 'no_answer' => 'No answer', 'busy' => 'Busy or switched off', 'wrong' => 'Wrong number'];

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
}
