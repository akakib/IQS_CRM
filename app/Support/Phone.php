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

    /** +880 1712-345678, 008801712345678, 8801712345678, 01712345678, 1712345678, ০১৭১২৩৪৫৬৭৮ -> 01712345678; anything else -> null. */
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D/', '', self::englishDigits((string) $input));

        $digits = match (true) {
            str_starts_with($digits, '008801') && strlen($digits) === 15 => substr($digits, 4),
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

        // Runs of digits, left to right: a number is the shortest string of neighbouring runs that makes one
        // ("+880 1712 345678" is three runs, "01712345678 01812345678" is two numbers).
        preg_match_all('/\d+/', $text, $m);
        $runs = $m[0];
        $found = [];
        for ($i = 0, $n = count($runs); $i < $n; $i++) {
            $joined = '';
            for ($j = $i; $j < $n && strlen($joined.$runs[$j]) <= 15; $j++) {
                $joined .= $runs[$j];
                if (($p = self::normalize($joined)) !== null) {
                    $found[] = $p;
                    $i = $j;
                    break;
                }
            }
        }

        return array_values(array_unique($found));
    }
}
