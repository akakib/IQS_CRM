<?php

namespace App\Services\Tracking;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Server-side conversion events (Meta CAPI now; GA4/TikTok later). When an
 * order reaches the stage chosen in tracking_event_settings, the event is
 * queued once per order (a 'sent' log blocks repeats) and sent after the
 * response. event_id is shared with the browser pixel for deduplication.
 */
class TrackingService
{
    /** @param string $stage order_created | record_verified | confirmed | delivered */
    public function handle(Order $order, string $stage): void
    {
        $settings = DB::table('tracking_event_settings')->where('is_active', true)->where('fire_on', $stage)->get();

        foreach ($settings as $s) {
            if (! in_array($order->channel, json_decode($s->channels, true) ?: [], true)) {
                continue;
            }
            $done = DB::table('tracking_event_logs')->where('order_id', $order->id)->where('platform', $s->platform)
                ->where('event_name', $s->event_name)->whereIn('status', ['sent', 'queued'])->exists();
            if ($done) {
                continue;
            }

            // Meta accepts event_time at most 7 days back; delivered uses the delivery time.
            $eventTime = now();
            $value = match ($s->value_basis) {
                'product_subtotal' => max(0, (float) $order->subtotal - (float) $order->discount_total),
                'delivered_amount' => (float) (DB::table('shipments')->where('order_id', $order->id)->where('is_active', true)->value('collected_amount') ?? $order->grand_total),
                default => (float) $order->grand_total,
            };

            $logId = DB::table('tracking_event_logs')->insertGetId([
                'order_id' => $order->id, 'platform' => $s->platform, 'event_name' => $s->event_name,
                'event_id' => $this->eventId($order), 'event_time' => $eventTime, 'value' => round($value, 2),
                'status' => 'queued', 'created_at' => now(),
            ]);

            app()->terminating(fn () => app(TrackingSender::class)->send($logId));
        }
    }

    /** Same id the website pixel used, so Meta counts the sale once. */
    public function eventId(Order $order): string
    {
        $template = $order->channel === 'web' && $order->external_ref
            ? (string) settings('tracking.web_event_id')
            : 'iqs_{order_no}';

        return strtr($template, ['{external_ref}' => (string) $order->external_ref, '{order_no}' => (string) $order->order_no]);
    }
}
