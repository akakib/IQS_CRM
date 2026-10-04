<?php

namespace App\Services\Catalog\Store;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WooCommerce REST v3 (consumer key/secret over HTTPS basic auth).
 * Simple product: PUT /products/{id}; variation: PUT /products/{id}/variations/{vid}.
 * Only used in production with credentials in .env; staging uses the fake.
 */
class WooCommerceDriver implements StoreDriver
{
    public function __construct(private array $config) {}

    public function name(): string
    {
        return 'woocommerce';
    }

    public function pushVariant(object $link, array $payload): void
    {
        if (blank($this->config['url'] ?? null) || blank($this->config['key'] ?? null) || blank($this->config['secret'] ?? null)) {
            throw new RuntimeException('WooCommerce is not configured (WOO_URL / WOO_KEY / WOO_SECRET).');
        }

        $body = [];
        if (array_key_exists('regular_price', $payload)) {
            $body['regular_price'] = (string) $payload['regular_price'];
            $body['sale_price'] = $payload['sale_price'] === null ? '' : (string) $payload['sale_price'];
        }
        if (isset($payload['stock_status'])) {
            $body['stock_status'] = $payload['stock_status'];
        }

        $path = $link->external_variant_id
            ? "products/{$link->external_product_id}/variations/{$link->external_variant_id}"
            : "products/{$link->external_product_id}";

        // Content (name, descriptions, SEO) lives on the parent product.
        if (isset($payload['name'])) {
            $content = [
                'name' => $payload['name'],
                'short_description' => (string) $payload['short_description'],
                'description' => (string) $payload['description'],
                'status' => $payload['status'],
                'meta_data' => [
                    ['key' => 'rank_math_title', 'value' => (string) $payload['seo_title']],
                    ['key' => 'rank_math_description', 'value' => (string) $payload['seo_description']],
                    ['key' => '_yoast_wpseo_title', 'value' => (string) $payload['seo_title']],
                    ['key' => '_yoast_wpseo_metadesc', 'value' => (string) $payload['seo_description']],
                ],
            ];
            $link->external_variant_id ? $this->put("products/{$link->external_product_id}", $content) : ($body += $content);
        }

        if ($body !== []) {
            $this->put($path, $body);
        }
    }

    private function put(string $path, array $body): void
    {
        $response = Http::withBasicAuth($this->config['key'], $this->config['secret'])
            ->acceptJson()->timeout(20)
            ->put(rtrim($this->config['url'], '/').'/wp-json/wc/v3/'.$path, $body);

        if (! $response->successful()) {
            throw new RuntimeException("WooCommerce {$path}: HTTP {$response->status()} ".mb_substr($response->body(), 0, 300));
        }
    }
}
