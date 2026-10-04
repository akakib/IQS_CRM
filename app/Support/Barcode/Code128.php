<?php

namespace App\Support\Barcode;

use InvalidArgumentException;

/**
 * Code 128 (set B) as inline SVG, so labels need no package or image
 * library. Set B covers printable ASCII 32-126, which is all our label
 * codes use (e.g. "IQ10001-2"). Checksum = (104 + Σ value × position) mod 103.
 */
final class Code128
{
    /** Bar/space widths for symbol values 0-106 (106 = stop, 7 elements). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /** @return list<int> symbol values including start, checksum and stop */
    public static function values(string $text): array
    {
        $values = [self::START_B];
        foreach (str_split($text) as $char) {
            $code = ord($char);
            if ($code < 32 || $code > 126) {
                throw new InvalidArgumentException('Code 128 B supports printable ASCII only.');
            }
            $values[] = $code - 32;
        }

        $sum = self::START_B;
        foreach (array_slice($values, 1) as $i => $v) {
            $sum += $v * ($i + 1);
        }
        $values[] = $sum % 103;
        $values[] = self::STOP;

        return $values;
    }

    /** Module widths, alternating bar/space starting with a bar. */
    public static function widths(string $text): string
    {
        return implode('', array_map(fn ($v) => self::PATTERNS[$v], self::values($text)));
    }

    public static function svg(string $text, float $module = 2, int $height = 60, int $quiet = 10): string
    {
        $x = $quiet * $module;
        $bars = '';
        foreach (str_split(self::widths($text)) as $i => $w) {
            $width = (int) $w * $module;
            if ($i % 2 === 0) {
                $bars .= sprintf('<rect x="%s" y="0" width="%s" height="%d"/>', $x, $width, $height);
            }
            $x += $width;
        }
        $total = $x + $quiet * $module;

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %d" width="%s" height="%d" role="img" aria-label="%s" shape-rendering="crispEdges"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%s</g></svg>',
            $total, $height, $total, $height, htmlspecialchars($text), $bars);
    }
}
