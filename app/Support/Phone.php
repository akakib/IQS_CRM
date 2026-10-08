<?php

namespace App\Support;

/** Bangladesh mobile numbers in one shape: 01XXXXXXXXX. */
final class Phone
{
    /** +880 1712-345678, 8801712345678, 01712345678, 1712345678, ০১৭১২৩৪৫৬৭৮ -> 01712345678; anything else -> null. */
    public static function normalize(?string $input): ?string
    {
        // Bangla digits typed or pasted from a chat count as digits too.
        $input = strtr((string) $input, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $digits = preg_replace('/\D/', '', $input);

        $digits = match (true) {
            str_starts_with($digits, '8801') && strlen($digits) === 13 => substr($digits, 2),
            str_starts_with($digits, '1') && strlen($digits) === 10 => '0'.$digits,
            default => $digits,
        };

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }
}
