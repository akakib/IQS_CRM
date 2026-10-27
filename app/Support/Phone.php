<?php

namespace App\Support;

/** Bangladesh mobile numbers in one shape: 01XXXXXXXXX. */
final class Phone
{
    /** Bangla digits typed or pasted from a chat, as English digits. */
    public static function englishDigits(string $input): string
    {
        return strtr($input, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    }

    /**
     * One shape for every way a number is written: 01712345678, 1712345678 (no 0), +880 1712-345678, +880 01712345678,
     * +88 1712345678, 88 01712345678, 0088 01712345678, 008801712345678, ০১৭১২৩৪৫৬৭৮ -> 01712345678; anything else -> null.
     */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D/', '', self::englishDigits((string) $input));
        // The international 00 (or a stray 0), then the country code written as 880 or 88, with or without the 0 after it.
        $digits = preg_replace('/^(?:00|0)?880?(?=0?1[3-9]\d{8}$)/', '', $digits);
        if (preg_match('/^1[3-9]\d{8}$/', $digits)) {
            $digits = '0'.$digits;
        }

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    /**
     * The start of a number as someone typed it, ready for a LIKE search: "+880 17", "88017", "১৭১২", "017 12" all
     * become "0171…"; null when there is no digit. Used by every list that searches orders by phone.
     */
    public static function searchPrefix(?string $input): ?string
    {
        $digits = preg_replace('/\D/', '', self::englishDigits((string) $input));
        if ($digits === '') {
            return null;
        }
        $digits = preg_replace('/^(?:00|0)?880?(?=0?1[3-9])/', '', $digits);

        return preg_match('/^1[3-9]/', $digits) ? '0'.$digits : $digits;
    }

    /** OR-conditions for an order's phone or second phone starting with what was typed (the orders table alias). */
    public static function orWhereOrder(object $w, ?string $prefix, string $alias = 'o'): object
    {
        return $prefix === null ? $w : $w->orWhere("{$alias}.ship_phone", 'like', $prefix.'%')->orWhere("{$alias}.ship_alt_phone", 'like', $prefix.'%');
    }

    /** Text with every phone number in it hidden ("call 01712345678 first" -> "call [phone] first"), for people who must not see contacts. */
    public static function hideIn(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return preg_replace('/(?:\+?88)?[0০][1১][\d০-৯](?:[\s-]?[\d০-৯]){8}/u', '[phone]', $text);
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /**
     * Every mobile number in a cell someone filled by hand, each in one shape, in the order written:
     * "01712-345678 / 01812345678", "+880 1712 345678, 01912345678 (husband)", "01712 345 678",
     * "Phone: ০১৭১২৩৪৫৬৭৮", "1.712345678E9" all come out right. A number with a digit too many or too
     * few is not guessed at. Nothing usable -> [].
     *
     * @return list<string>
     */
    public static function extract(?string $input): array
    {
        $text = self::englishDigits(trim((string) $input));
        if ($text === '') {
            return [];
        }
        // A number Excel turned into a float: 1.712345678E9 or 1712345678.0 (only when no digit was lost).
        if (preg_match('/^\d+(?:\.(\d+))?E\+?(\d+)$/i', $text, $m)) {
            $text = strlen($m[1] ?? '') >= (int) $m[2] ? sprintf('%.0f', (float) $text) : '';
        } elseif (preg_match('/^\d+\.0+$/', $text)) {
            $text = substr($text, 0, strpos($text, '.'));
        }

        // Runs of digits, left to right. A run of 10 or more digits is a whole number as the writer typed it: right,
        // or wrong and left alone (never completed by a stray digit after it). A short run ("+880", "01712") takes the
        // runs after it until a number appears; once ten digits are reached with no number, it is given up.
        preg_match_all('/\d+/', $text, $m);
        $runs = $m[0];
        $found = [];
        for ($i = 0, $n = count($runs); $i < $n; $i++) {
            if (strlen($runs[$i]) >= 10) {
                if (($p = self::normalize($runs[$i])) !== null) {
                    $found[] = $p;
                }

                continue;
            }
            $joined = '';
            for ($j = $i; $j < $n; $j++) {
                // A stray one- or two-digit run never completes a number that is nearly whole ("171234567 8").
                if ($j > $i && strlen($joined) >= 8 && strlen($runs[$j]) <= 2) {
                    break;
                }
                $joined .= $runs[$j];
                if (($p = self::normalize($joined)) !== null) {
                    $found[] = $p;
                    $i = $j;
                    break;
                }
                if (strlen($joined) >= 10) {
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }
}
