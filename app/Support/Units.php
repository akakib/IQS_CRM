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

    /**
     * The size of one item as written in its name ("500g", "3kg", "350 gm", "600ml", "1.5 L", "৫০০ গ্রাম"):
     * [amount, 'g' | 'ml'], kilos and litres turned into grams and ml. The first name that has one wins
     * (the variant's, then the product's). Null when no name says it.
     *
     * @return array{0: float, 1: string}|null
     */
    public static function sizeFromName(?string ...$names): ?array
    {
        $bn = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];
        foreach ($names as $name) {
            $text = strtr((string) $name, $bn);
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(kgs?|kilo|gms?|grams?|g|ml|ltrs?|litres?|liters?|l|কেজি|গ্রাম|মিলি|লিটার)(?![a-z])/iu', $text, $m)) {
                $qty = (float) str_replace(',', '.', $m[1]);
                $unit = mb_strtolower($m[2]);
                [$times, $as] = match (true) {
                    in_array($unit, ['kg', 'kgs', 'kilo', 'কেজি'], true) => [1000, 'g'],
                    in_array($unit, ['ml', 'মিলি'], true) => [1, 'ml'],
                    in_array($unit, ['l', 'ltr', 'ltrs', 'litre', 'litres', 'liter', 'liters', 'লিটার'], true) => [1000, 'ml'],
                    default => [1, 'g'],
                };
                if ($qty > 0) {
                    return [round($qty * $times, 3), $as];
                }
            }
        }

        return null;
    }

    /** Grams and ml in the big unit once there is enough: "42.5 kg", "750 g", "3.6 L", "250 ml". */
    public static function amount(float $grams, float $ml = 0): string
    {
        $one = fn (float $n, string $small, string $big) => $n >= 1000
            ? rtrim(rtrim(number_format($n / 1000, 2, '.', ','), '0'), '.').' '.$big
            : rtrim(rtrim(number_format($n, 1, '.', ','), '0'), '.').' '.$small;
        $parts = array_filter([$grams > 0 ? $one($grams, 'g', 'kg') : null, $ml > 0 ? $one($ml, 'ml', 'L') : null]);

        return $parts ? implode(' · ', $parts) : '-';
    }

    /** The size of one piece of an order line ("700 g", "1 kg", "800 ml"), for the packer; null when sold by weight or no size. */
    public static function itemSize(object $item): ?string
    {
        $v = $item->variant ?? null;
        if (self::measured($item->unit ?? null) || ! $v || ! in_array($v->size_unit, ['g', 'ml'], true) || (float) $v->size_qty <= 0) {
            return null;
        }

        return self::amount($v->size_unit === 'g' ? (float) $v->size_qty : 0, $v->size_unit === 'ml' ? (float) $v->size_qty : 0);
    }

    /** Shipping weight of one order line, in grams. */
    public static function weight(float $qty, ?string $unit, ?int $weightEach): int
    {
        return (int) round(self::measured($unit) ? $qty * self::GRAMS_PER[$unit] : ($weightEach ?? 0) * $qty);
    }
}
