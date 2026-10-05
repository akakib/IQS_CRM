<?php

namespace App\Support;

/**
 * The units a product is sold in. Counted units (piece, packet, box) are
 * whole things; measured units (gram, kg, ml, litre) are an amount, and the
 * price is per one of that unit.
 */
class Units
{
    /** key => label shown to staff */
    public const LABELS = [
        'pcs' => 'Pcs', 'packet' => 'Packet', 'box' => 'Box',
        'g' => 'Gram', 'kg' => 'KG', 'ml' => 'ML', 'l' => 'Litre',
    ];

    /** Measured units and how many grams one of them weighs for delivery (liquids counted as water). */
    public const GRAMS_PER = ['g' => 1, 'kg' => 1000, 'ml' => 1, 'l' => 1000];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function measured(?string $unit): bool
    {
        return isset(self::GRAMS_PER[$unit]);
    }

    /** "×2" for pieces, "×2 packet", "500 g", "1.5 kg", "250 ml". */
    public static function qty(float|string|null $qty, ?string $unit): string
    {
        $n = rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.');

        return match (true) {
            self::measured($unit) => $n.' '.$unit,
            $unit === 'packet', $unit === 'box' => '×'.$n.' '.$unit,
            default => '×'.$n,
        };
    }

    /** Shipping weight of one order line, in grams. */
    public static function weight(float $qty, ?string $unit, ?int $weightEach): int
    {
        return (int) round(self::measured($unit) ? $qty * self::GRAMS_PER[$unit] : ($weightEach ?? 0) * $qty);
    }
}
