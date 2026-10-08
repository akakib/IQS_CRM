<?php

namespace App\Services\Orders;

use App\Services\Catalog\Store\WooApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The safety net under the website webhooks: the website's orders changed
 * recently are read through the API. One missing here, or changed (paid,
 * cancelled) without its webhook arriving, goes through the same intake as
 * a webhook. Webhooks stay the fast path; this only catches what WP-Cron
 * delayed or a failed delivery lost.
 *
 * Never twice: one sweep at a time (lock), an order already here with
 * nothing new is skipped, the same website data is never queued twice
 * (inbox key), the intake locks per website order and updates the one it
 * finds, and the database refuses a second order with the same website id.
 */
class WooOrderSweep
{
    /** 50 a page, at most this many pages a run. */
    private const MAX_PAGES = 10;

    public function __construct(private WooApi $api, private WooOrderIntake $intake) {}

    /** The 10-minute background run: the last 2 hours. @return int orders handed to the intake */
    public function run(): int
    {
        $r = $this->sync(2);

        return $r['new'] + $r['updated'];
    }

    /**
     * @return array{new: int, updated: int, checked: int, busy: bool, error: ?string}
     */
    public function sync(int $hours): array
    {
        $result = ['new' => 0, 'updated' => 0, 'checked' => 0, 'busy' => false, 'error' => null];
        if (! $this->api->configured()) {
            return ['error' => __('Website API keys are not set (Settings > Website connection).')] + $result;
        }
        $lock = Cache::lock('woo:sweep:running', 120);
        if (! $lock->get()) {
            return ['busy' => true] + $result;
        }

        $startedAt = now()->subSecond();
        try {
            $since = now()->subHours($hours)->utc()->format('Y-m-d\TH:i:s');
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $orders = $this->api->get('orders', [
                    'modified_after' => $since, 'per_page' => 50, 'page' => $page,
                    'orderby' => 'modified', 'order' => 'asc', 'status' => 'any',
                ])->json() ?: [];
                $this->handle($orders, $result, $startedAt);
                if (count($orders) < 50) {
                    break;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Website order sweep failed: '.$e->getMessage());
            $result['error'] = __('The website did not answer: :m', ['m' => mb_substr($e->getMessage(), 0, 150)]);
        } finally {
            $lock->release();
        }
        if ($result['new'] || $result['updated']) {
            Cache::put('woo:sweep:caught', now()->toIso8601String(), now()->addDays(30));
        }

        return $result;
    }

    private function handle(array $orders, array &$result, \Illuminate\Support\Carbon $startedAt): void
    {
        $orders = array_values(array_filter($orders, fn ($o) => isset($o['id'])));
        $result['checked'] += count($orders);
        $known = DB::table('orders')->where('channel', 'web')->whereIn('external_ref', array_map(fn ($o) => (string) $o['id'], $orders))
            ->get(['external_ref', 'external_status', 'external_paid_at'])->keyBy('external_ref');

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
            // insertOrIgnore on the unique (source, external_id): of two sweeps or a sweep and a retry, only one queues it.
            $queued = DB::table('integration_inbox')->insertOrIgnore([
                'source' => 'woocommerce', 'external_id' => $externalId, 'topic' => $here ? 'order.updated' : 'order.created',
                'payload' => $body, 'status' => 'received', 'received_at' => now(),
            ]);
            if (! $queued) {
                continue;
            }
            $inboxId = (int) DB::table('integration_inbox')->where('source', 'woocommerce')->where('external_id', $externalId)->value('id');
            $order = $this->intake->process($inboxId);
            if ($order && ! $here && $order->created_at?->gte($startedAt)) { // made by this sweep, not by a webhook a moment earlier
                $result['new']++;
            } elseif ($order) {
                $result['updated']++;
            }
        }
    }
}
