<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerFraudCheck;
use App\Models\FraudCheckProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Runs every active provider for a customer's primary phone. A result newer
 * than the provider's cache_hours is reused (vendor rate limits); every real
 * call is kept (append-only) so "why was this order verified?" can be
 * answered months later.
 *
 * @return Collection<int, CustomerFraudCheck> latest check per provider
 */
class FraudCheckService
{
    /** @var array<string, string> provider key => "Steadfast check failed (HTTP 429)" from the last check() */
    private array $failures = [];

    /** Providers that did not answer in the last check() (no saved result either): their history is unknown. */
    public function failures(): array
    {
        return $this->failures;
    }

    public function check(Customer $customer, bool $force = false): Collection
    {
        $results = collect();
        $this->failures = [];

        foreach (FraudCheckProvider::where('is_active', true)->orderBy('sort_order')->get() as $provider) {
            $cached = $force ? null : CustomerFraudCheck::where('customer_id', $customer->id)->where('provider_id', $provider->id)
                ->where('phone', $customer->primary_phone)
                ->where('checked_at', '>=', now()->subHours($provider->cache_hours))
                ->latest('checked_at')->first();

            if ($cached) {
                $results->put($provider->system_key, $cached);

                continue;
            }

            try {
                /** @var FraudCheckDriver $driver */
                $driver = app($provider->driver_class);
                $r = $driver->check($customer, $customer->primary_phone, $provider->credentials);
                $results->put($provider->system_key, CustomerFraudCheck::create([
                    'customer_id' => $customer->id,
                    'provider_id' => $provider->id,
                    'phone' => $customer->primary_phone,
                    'total_parcels' => $r->totalParcels,
                    'delivered' => $r->delivered,
                    'cancelled' => $r->cancelled,
                    'success_rate' => $r->successRate,
                    'raw_response' => $r->raw,
                    'checked_at' => now(),
                ]));
            } catch (\Throwable $e) {
                // One vendor being down must not block an order; it has no result, and nothing is saved so the next check asks again.
                Log::warning("Fraud check {$provider->system_key} failed: {$e->getMessage()}");
                $why = match (true) {
                    (bool) preg_match('/HTTP \d{3}/', $e->getMessage(), $m) => $m[0],
                    str_contains($e->getMessage(), 'empty answer') => 'empty answer',
                    $e instanceof \Illuminate\Http\Client\ConnectionException => 'no answer',
                    str_contains($e->getMessage(), 'keys are missing') => 'keys missing',
                    default => 'error',
                };
                $this->failures[$provider->system_key] = "{$provider->name} check failed ({$why})";
            }
        }

        return $results;
    }
}
