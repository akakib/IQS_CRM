<?php

namespace App\Services\Tracking;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Sends one queued tracking event. Real Meta CAPI only in production with
 * META_PIXEL_ID + META_CAPI_TOKEN; everywhere else the call is simulated and
 * logged as sent with {"fake": true}, so staging shows the full flow.
 */
class TrackingSender
{
    public function send(int $logId): void
    {
        $log = DB::table('tracking_event_logs')->where('id', $logId)->where('status', 'queued')->first();
        if (! $log) {
            return;
        }
        $order = DB::table('orders')->where('id', $log->order_id)->first();
        $items = DB::table('order_items')->where('order_id', $log->order_id)->get(['sku_snapshot', 'qty', 'unit_price']);

        $hash = fn (?string $v) => $v ? hash('sha256', strtolower(trim($v))) : null;
        $phone = $order->ship_phone ? '88'.$order->ship_phone : null; // E.164 without +
        [$first, $last] = array_pad(explode(' ', trim((string) $order->ship_name), 2), 2, null);

        $payload = ['data' => [[
            'event_name' => $log->event_name,
            'event_time' => \Illuminate\Support\Carbon::parse($log->event_time)->timestamp,
            'event_id' => $log->event_id,
            'action_source' => $order->channel === 'web' ? 'website' : 'business_messaging',
            'user_data' => array_filter([
                'ph' => [$hash($phone)],
                'fn' => [$hash($first)],
                'ln' => $last ? [$hash($last)] : null,
                'ct' => $order->ship_district ? [$hash(str_replace(' ', '', $order->ship_district))] : null,
                'country' => [$hash('bd')],
                'external_id' => [$hash((string) $order->customer_id)],
                'fbp' => $order->fbp,
                'fbc' => $order->fbc,
            ]),
            'custom_data' => [
                'currency' => 'BDT',
                'value' => (float) $log->value,
                'order_id' => $order->order_no,
                'content_type' => 'product',
                'contents' => $items->map(fn ($i) => ['id' => $i->sku_snapshot, 'quantity' => (float) $i->qty, 'item_price' => (float) $i->unit_price])->all(),
            ],
        ]]];

        $pixel = config('services.meta.pixel_id');
        $token = config('services.meta.capi_token');
        $real = app()->isProduction() && $pixel && $token;

        try {
            if ($real) {
                $response = Http::timeout(15)->post("https://graph.facebook.com/v19.0/{$pixel}/events?access_token={$token}", $payload);
                $ok = $response->successful();
                $body = $response->json() ?? ['raw' => mb_substr($response->body(), 0, 500)];
            } else {
                $ok = true;
                $body = ['fake' => true, 'reason' => 'Not production or Meta keys not set'];
            }
        } catch (\Throwable $e) {
            $ok = false;
            $body = ['error' => $e->getMessage()];
        }

        DB::table('tracking_event_logs')->where('id', $logId)->update([
            'status' => $ok ? 'sent' : 'failed',
            'request_payload' => json_encode($payload),
            'response' => json_encode($body),
        ]);
    }
}
