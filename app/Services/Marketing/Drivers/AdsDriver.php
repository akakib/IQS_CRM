<?php

namespace App\Services\Marketing\Drivers;

interface AdsDriver
{
    /**
     * Daily numbers for one ad account, days in the account's timezone.
     *
     * @return array<string, array{spend_usd: float, impressions: int, clicks: int, messages: int, purchases: int, reported_value: float}> date => metrics
     */
    public function daily(object $account, string $from, string $to): array;

    public function source(): string;
}
