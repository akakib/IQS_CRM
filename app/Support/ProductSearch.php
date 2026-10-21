<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one product search: what someone types, plus every word that means the same (Settings > Search words: one line
 * per group, "খেজুর, dates, khejur"), matched in the name, size and SKU first and then in the short and long
 * description. So "খেজুর" finds "Saudi Dates" and "dates" finds "কালমি খেজুর". Used by every product search box.
 */
final class ProductSearch
{
    /** @return list<string> what was typed and the words that mean the same, lower case */
    public static function terms(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') {
            return [];
        }
        $terms = [$q];
        foreach (self::groups() as $words) {
            foreach ($words as $w) {
                // The word itself, the start of a word (3+ letters typed), or a longer phrase that holds a word of 4+ letters.
                if ($w === $q || (mb_strlen($q) >= 3 && str_starts_with($w, $q)) || (mb_strlen($w) >= 4 && str_contains($q, $w))) {
                    array_push($terms, ...$words);
                    break;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Narrow a query to products matching the terms. $variantText is the variant search_text column, $productId the
     * column holding the product id (both on the query being narrowed).
     */
    public static function apply(object $query, string $q, string $variantText = 'search_text', string $productId = 'product_id'): object
    {
        $terms = self::terms($q);
        if ($terms === []) {
            return $query;
        }

        return $query->where(function ($w) use ($terms, $variantText, $productId) {
            foreach ($terms as $t) {
                $w->orWhere($variantText, 'like', '%'.$t.'%');
            }
            $w->orWhereIn($productId, self::inDescription($terms));
        });
    }

    /**
     * Product ids whose short or long description has a word starting with one of the terms ("dates" finds
     * "dates" and "Dates," but not "updates"); a description is long, so a part of a word would match too much.
     */
    public static function inDescription(array $terms): \Illuminate\Database\Query\Builder
    {
        return DB::table('products')->select('id')->where(function ($w) use ($terms) {
            foreach (array_filter($terms, fn ($t) => mb_strlen($t) >= 2) as $t) {
                foreach (['short_description', 'description'] as $col) {
                    $w->orWhere($col, 'like', $t.'%')->orWhere($col, 'like', '% '.$t.'%')->orWhere($col, 'like', '%>'.$t.'%')->orWhere($col, 'like', '%('.$t.'%');
                }
            }
        });
    }

    /** @return list<list<string>> */
    private static function groups(): array
    {
        static $cache = [];
        $raw = (string) settings('search.words');

        return $cache[$raw] ??= collect(preg_split('/\R/u', $raw))
            ->map(fn ($line) => collect(explode(',', $line))->map(fn ($w) => mb_strtolower(trim($w)))->filter()->unique()->values()->all())
            ->filter(fn ($g) => count($g) > 1)->values()->all();
    }
}
