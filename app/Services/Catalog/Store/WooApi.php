<?php

namespace App\Services\Catalog\Store;

use App\Models\WebsiteAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads from the website's WooCommerce REST API with the keys saved in
 * Settings (the server .env as a fallback). Read calls only: products,
 * variations, categories, the weight unit. Nothing here changes the website.
 */
class WooApi
{
    private ?array $config = null;

    public function configured(): bool
    {
        return (bool) $this->config();
    }

    /** @return array{url: string, key: string, secret: string}|null */
    public function config(): ?array
    {
        if ($this->config !== null) {
            return $this->config ?: null;
        }
        $account = WebsiteAccount::current();
        $c = $account
            ? ['url' => $account->url, 'key' => $account->consumer_key, 'secret' => $account->consumer_secret]
            : ['url' => (string) config('store.woocommerce.url'), 'key' => (string) config('store.woocommerce.key'), 'secret' => (string) config('store.woocommerce.secret')];
        $this->config = filled($c['url']) && filled($c['key']) && filled($c['secret']) ? $c : [];

        return $this->config ?: null;
    }

    /** GET wc/v3/{path}; throws with the website's answer when it is not 2xx. */
    public function get(string $path, array $query = []): Response
    {
        $c = $this->config();
        if (! $c) {
            throw new RuntimeException(__('Website API keys are not set (Settings > Website connection).'));
        }
        $response = Http::withBasicAuth($c['key'], $c['secret'])->acceptJson()->timeout(30)
            ->get(rtrim($c['url'], '/').'/wp-json/wc/v3/'.ltrim($path, '/'), $query);
        if (! $response->successful()) {
            throw new RuntimeException("Website API {$path}: HTTP {$response->status()} ".mb_substr(strip_tags((string) $response->body()), 0, 200));
        }

        return $response;
    }

    /** How many products the website has (a cheap call that also proves the keys). */
    public function productCount(): int
    {
        return (int) $this->get('products', ['per_page' => 1, 'status' => 'any'])->header('X-WP-Total');
    }
}
