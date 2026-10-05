<?php

namespace App\Services\Marketing\Drivers;

use Illuminate\Support\Carbon;

/** Steady made-up numbers (same date = same numbers) until real API access exists. */
class FakeAdsDriver implements AdsDriver
{
    public function daily(object $account, string $from, string $to): array
    {
        $out = [];
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $h = crc32($account->id.'-'.$d->toDateString());
            $spend = 20 + ($h % 4000) / 100; // $20 to $60
            $out[$d->toDateString()] = [
                'spend_usd' => round($spend, 2),
                'impressions' => (int) ($spend * 900),
                'clicks' => (int) ($spend * 12),
                'messages' => $account->platform === 'meta' ? (int) ($spend * 1.5) : 0,
                'purchases' => (int) ($spend / 4),
                'reported_value' => round($spend / 4 * 1800, 2),
            ];
        }

        return $out;
    }

    public function source(): string
    {
        return 'fake';
    }
}
