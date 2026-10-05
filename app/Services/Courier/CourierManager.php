<?php

namespace App\Services\Courier;

use InvalidArgumentException;

/**
 * Picks the courier driver from config('courier.driver'). The testing and
 * staging environments ALWAYS get the fake driver, whatever .env says,
 * because a real Steadfast booking is a real parcel.
 */
class CourierManager
{
    private ?CourierDriver $driver = null;

    public function driver(): CourierDriver
    {
        return $this->driver ??= $this->make();
    }

    public function resolvedName(): string
    {
        return $this->isForcedFake() ? 'fake' : (string) config('courier.driver', 'fake');
    }

    public function isForcedFake(): bool
    {
        return app()->environment(['testing', 'staging', 'local']) && ! config('courier.allow_real_in_local');
    }

    /** Keys typed in Settings > Courier accounts win over the server .env. */
    private function savedKeys(string $courier): array
    {
        $account = \App\Models\CourierAccount::defaultFor($courier);

        return $account ? ['api_key' => $account->api_key, 'secret_key' => $account->secret_key] : [];
    }

    private function make(): CourierDriver
    {
        return match ($this->resolvedName()) {
            'fake' => new FakeCourierDriver,
            'steadfast' => new SteadfastDriver(array_merge(config('courier.steadfast', []), $this->savedKeys('steadfast'))),
            default => throw new InvalidArgumentException('Unknown courier driver: '.config('courier.driver')),
        };
    }
}
