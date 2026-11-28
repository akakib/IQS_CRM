<?php

namespace App\Support;

/**
 * The address on a parcel sticker: the full address, the district by name (not the website's BD-10 code),
 * a part the address already says only once, and any phone number typed into it taken out (no phone on a sticker).
 */
class LabelAddress
{
    public static function of(object $order): string
    {
        $addr = trim((string) preg_replace(['/(?:\+?88)?[0০][1১][\d০-৯](?:[\s-]?[\d০-৯]){8}/u', '/\s{2,}/u', '/\s*,\s*(,\s*)+/u'], ['', ' ', ', '], (string) ($order->ship_address ?? '')), " ,");
        $parts = collect([$order->ship_thana ?? null, BdDistricts::name($order->ship_district ?? null)])->filter()
            ->reject(fn ($p) => $addr !== '' && mb_stripos($addr, $p) !== false)->unique();

        return collect([$addr])->filter()->merge($parts)->join(', ');
    }
}
