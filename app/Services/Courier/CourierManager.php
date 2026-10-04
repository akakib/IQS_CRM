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

    private function make(): CourierDriver
    {
        return match ($this->resolvedName()) {
            'fake' => new FakeCourierDriver,
            'steadfast' => new SteadfastDriver(config('courier.steadfast', [])),
            default => throw new InvalidArgumentException('Unknown courier driver: '.config('courier.driver')),
        };
    }
}
