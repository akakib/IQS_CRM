<?php

namespace App\Services\Catalog;

use App\Services\Catalog\Store\StoreDriver;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * One-way sync IQS -> website. queue() records WHAT changed; run() sends the
 * LATEST values (built at send time, so ten edits become one update). Only
 * variants linked to a website product are queued. Failures retry with
 * backoff; after MAX_ATTEMPTS the Manager is alerted (sync_failed).
 */
class ChannelSync
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private StoreDriver $driver) {}

    /** @param list<string> $fields price | stock_status | content */
    public function queue(int $variantId, array $fields): void
    {
        $fields = array_values(array_unique(array_filter($fields)));
        if ($fields === []) {
            return;
        }

        $channels = DB::table('channel_product_links')->where('variant_id', $variantId)->pluck('channel')->unique();

        foreach ($channels as $channel) {
            $pending = DB::table('channel_sync_jobs')
                ->where('variant_id', $variantId)->where('channel', $channel)->where('status', 'queued')->first(['id', 'fields']);

            if ($pending) {
                $merged = array_values(array_unique([...json_decode($pending->fields, true), ...$fields]));
                DB::table('channel_sync_jobs')->where('id', $pending->id)->update(['fields' => json_encode($merged), 'updated_at' => now()]);
            } else {
                DB::table('channel_sync_jobs')->insert([
                    'variant_id' => $variantId, 'channel' => $channel, 'fields' => json_encode($fields),
                    'payload' => '{}', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** Send due jobs. @return array{sent: int, failed: int} */
    public function run(int $limit = 50): array
    {
        $jobs = DB::table('channel_sync_jobs')
            ->where('status', 'queued')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get();

        $result = ['sent' => 0, 'failed' => 0];

        foreach ($jobs as $job) {
            $payload = $this->payload($job->variant_id, json_decode($job->fields, true));
            $link = DB::table('channel_product_links')->where('variant_id', $job->variant_id)->where('channel', $job->channel)->first();

            try {
                if (! $payload || ! $link) {
                    throw new \RuntimeException('Variant or website link no longer exists.');
                }
                $this->driver->pushVariant($link, $payload);

                DB::table('channel_sync_jobs')->where('id', $job->id)->update([
                    'status' => 'sent', 'payload' => json_encode($payload), 'attempts' => $job->attempts + 1,
                    'sent_at' => now(), 'last_error' => null, 'updated_at' => now(),
                ]);
                DB::table('channel_product_links')->where('id', $link->id)->update(['last_synced_at' => now(), 'last_sync_status' => 'ok']);
                $result['sent']++;
            } catch (\Throwable $e) {
                $attempts = $job->attempts + 1;
                $final = $attempts >= self::MAX_ATTEMPTS;
                DB::table('channel_sync_jobs')->where('id', $job->id)->update([
                    'status' => $final ? 'failed' : 'queued',
                    'attempts' => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    'next_attempt_at' => now()->addMinutes(2 ** $attempts), // 2, 4, 8, 16 min
                    'payload' => json_encode($payload ?? []),
                    'updated_at' => now(),
                ]);
                if ($link) {
                    DB::table('channel_product_links')->where('id', $link->id)->update(['last_sync_status' => 'failed']);
                }
                if ($final) {
                    app(NotificationService::class)->send('sync_failed', __('Website sync failed for SKU :sku', ['sku' => $payload['sku'] ?? $job->variant_id]),
                        $e->getMessage(), ['group_key' => 'sync_failed', 'subject' => ['product_variant', $job->variant_id]]);
                }
                $result['failed']++;
            }
        }

        return $result;
    }

    /** Latest values of the requested field groups. */
    private function payload(int $variantId, array $fields): ?array
    {
        $v = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('price_lists as pl', fn ($j) => $j->where('pl.system_key', 'online'))
            ->leftJoin('variant_prices as vp', fn ($j) => $j->on('vp.variant_id', '=', 'v.id')->on('vp.price_list_id', '=', 'pl.id'))
            ->where('v.id', $variantId)->whereNull('v.deleted_at')
            ->first(['v.sku', 'v.availability_status', 'v.is_active', 'p.name', 'p.short_description', 'p.description',
                'p.seo_title', 'p.seo_description', 'p.status', 'vp.regular_price', 'vp.sale_price']);

        if (! $v) {
            return null;
        }

        $payload = ['sku' => $v->sku];
        if (in_array('price', $fields, true)) {
            $payload['regular_price'] = $v->regular_price;
            $payload['sale_price'] = $v->sale_price;
        }
        if (in_array('stock_status', $fields, true)) {
            // The website never shows "pre-order": backorder is published as in stock.
            $payload['stock_status'] = $v->availability_status === 'out_of_stock' || ! $v->is_active ? 'outofstock' : 'instock';
        }
        if (in_array('content', $fields, true)) {
            $payload += [
                'name' => $v->name, 'short_description' => $v->short_description, 'description' => $v->description,
                'seo_title' => $v->seo_title, 'seo_description' => $v->seo_description,
                'status' => $v->status === 'active' ? 'publish' : 'draft',
            ];
        }

        return $payload;
    }
}
