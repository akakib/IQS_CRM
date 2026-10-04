<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Delivery charge comes from delivery_charge_rules, never typed by agents.
 * Matching rule with the lowest priority number wins; among equals a
 * free-shipping threshold (higher min_order_total) beats the normal charge,
 * then a zone rule beats an any-zone rule.
 */
class DeliveryCharges
{
    public function for(?int $zoneId, int $weightG, float $orderTotal): float
    {
        $rules = Cache::rememberForever('delivery_charge_rules', fn () => DB::table('delivery_charge_rules')->where('is_active', true)->get()->all());

        $match = collect($rules)
            ->filter(fn ($r) => ($r->zone_id === null || (int) $r->zone_id === $zoneId)
                && $weightG >= (int) $r->min_weight_g
                && ($r->max_weight_g === null || $weightG <= (int) $r->max_weight_g)
                && $orderTotal >= (float) $r->min_order_total)
            ->sortBy([
                fn ($a, $b) => $a->priority <=> $b->priority,
                fn ($a, $b) => (float) $b->min_order_total <=> (float) $a->min_order_total,
                fn ($a, $b) => ($b->zone_id !== null) <=> ($a->zone_id !== null),
            ])
            ->first();

        return $match ? round((float) $match->charge, 2) : 0.0;
    }

    public static function forget(): void
    {
        Cache::forget('delivery_charge_rules');
    }
}
