<?php

namespace App\Services\Orders;

use App\Services\Catalog\Store\WooApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The safety net under the website webhooks: every 10 minutes the website's
 * orders changed in the last 2 hours are read through the API. One missing
 * here, or changed (paid, cancelled) without its webhook arriving, goes
 * through the same intake as a webhook. Webhooks stay the fast path; this
 * only catches what WP-Cron delayed or a failed delivery lost.
 */
class WooOrderSweep
{
    public function __construct(private WooApi $api, private WooOrderIntake $intake) {}

    /** @return int orders handed to the intake */
    public function run(): int
    {
        if (! $this->api->configured()) {
            return 0;
        }
        try {
            $orders = $this->api->get('orders', [
                'modified_after' => now()->subHours(2)->utc()->format('Y-m-d\TH:i:s'),
                'per_page' => 50, 'orderby' => 'modified', 'order' => 'asc', 'status' => 'any',
            ])->json() ?: [];
        } catch (Throwable $e) {
            Log::warning('Website order sweep failed: '.$e->getMessage());

            return 0;
        }

        $known = DB::table('orders')->where('channel', 'web')->whereIn('external_ref', array_map(fn ($o) => (string) $o['id'], $orders))
            ->get(['external_ref', 'external_status', 'external_paid_at'])->keyBy('external_ref');
        $handed = 0;
        foreach ($orders as $o) {
            $here = $known[(string) $o['id']] ?? null;
            $status = strtolower((string) ($o['status'] ?? ''));
            $paid = filled($o['date_paid_gmt'] ?? null) || filled($o['date_paid'] ?? null);
            // Nothing new for us: already here with the same status and payment.
            if ($here && $here->external_status === $status && ($here->external_paid_at !== null || ! $paid)) {
                continue;
            }
            $body = json_encode($o);
            $externalId = 'order:'.$o['id'].':'.substr(sha1($body), 0, 16);
            if (DB::table('integration_inbox')->where('source', 'woocommerce')->where('external_id', $externalId)->exists()) {
                continue;
            }
            $inboxId = DB::table('integration_inbox')->insertGetId([
                'source' => 'woocommerce', 'external_id' => $externalId, 'topic' => $here ? 'order.updated' : 'order.created',
                'payload' => $body, 'status' => 'received', 'received_at' => now(),
            ]);
            $this->intake->process($inboxId);
            $handed++;
        }
        if ($handed) {
            Cache::put('woo:sweep:caught', now()->toIso8601String(), now()->addDays(30));
        }

        return $handed;
    }
}
