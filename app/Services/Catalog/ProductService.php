<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every product/variant/price/availability write goes through here so that
 * price history, availability events and the website sync queue can never
 * be skipped by a screen or an import.
 */
class ProductService
{
    /** Fields whose change must reach the website. */
    private const CONTENT_FIELDS = ['name', 'short_description', 'description', 'seo_title', 'seo_description', 'status', 'category_id'];

    public function __construct(private ChannelSync $sync) {}

    /**
     * @param  array  $data  product fields
     * @param  list<array>  $variants  rows: id?, sku, barcode?, name, unit, pack_qty, weight_g?, cost_price?, is_active?, prices: [list_key => [regular, sale?]]
     */
    public function save(Product $product, array $data, array $variants, ?int $userId, string $source = 'manual'): Product
    {
        return DB::transaction(function () use ($product, $data, $variants, $userId, $source) {
            $contentChanged = $this->saveProduct($product, $data, $userId);
            $keptIds = [];

            foreach (array_values($variants) as $i => $row) {
                $variant = isset($row['id'])
                    ? $product->variants()->whereKey($row['id'])->firstOrFail()
                    : new ProductVariant(['product_id' => $product->id]);
                $keptIds[] = $this->saveVariant($product, $variant, $row + ['sort_order' => $i], $userId, $source, $contentChanged)->id;
            }

            // Variants removed in the form are soft-deleted (never hard-deleted).
            $product->variants()->whereNotIn('id', $keptIds)->get()->each->delete();

            return $product;
        });
    }

    /** Product fields only. @return bool whether website-visible content changed */
    public function saveProduct(Product $product, array $data, ?int $userId): bool
    {
        $data = Arr::only($data, (new Product)->getFillable());
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? null ?: ($data['name'] ?? $product->name), $product->id);
        $data['updated_by'] = $userId;
        if (! $product->exists) {
            $data['created_by'] = $userId;
        }

        $contentChanged = ! $product->exists || array_intersect(self::CONTENT_FIELDS, array_keys(array_diff_assoc(
            array_map(fn ($v) => (string) $v, Arr::only($data, self::CONTENT_FIELDS)),
            array_map(fn ($v) => (string) $v, Arr::only($product->getAttributes(), self::CONTENT_FIELDS)),
        ))) !== [];

        $product->fill($data)->save();

        return $contentChanged;
    }

    /**
     * One variant with its prices. $queueSync = false for imports FROM the
     * website (pushing the same values straight back would be pointless).
     */
    public function saveVariant(Product $product, ProductVariant $variant, array $row, ?int $userId, string $source = 'manual', bool $contentChanged = false, bool $queueSync = true): ProductVariant
    {
        $this->lists ??= DB::table('price_lists')->pluck('id', 'system_key');
        $oldCost = $variant->exists ? $variant->cost_price : null;
        $order = (int) ($row['sort_order'] ?? $variant->sort_order ?? 0);

        $variant->fill([
            'product_id' => $product->id,
            'sku' => trim($row['sku']),
            'barcode' => ($row['barcode'] ?? null) ?: null,
            'shelf_code' => array_key_exists('shelf_code', $row) ? (($row['shelf_code'] ?? null) ?: null) : $variant->shelf_code,
            'name' => trim(($row['name'] ?? '') ?: 'Default'),
            'unit' => $row['unit'] ?? 'pcs',
            'pack_qty' => $row['pack_qty'] ?? 1,
            'weight_g' => ($row['weight_g'] ?? null) ?: null,
            'is_active' => (bool) ($row['is_active'] ?? true),
            'is_default' => $order === 0,
            'sort_order' => $order,
        ]);
        // Staff whose role hides cost never send it; keep the stored value then.
        if (array_key_exists('cost_price', $row)) {
            $variant->cost_price = $row['cost_price'] === '' ? null : $row['cost_price'];
        }
        if (isset($row['availability_status']) && ! $variant->exists) {
            $variant->availability_status = $row['availability_status'];
        }
        $variant->search_text = ProductVariant::searchTextFor($product->name, $variant->name, $variant->sku, $variant->barcode);
        $variant->save();

        $changed = $this->savePrices($variant, $row['prices'] ?? [], $this->lists, $userId, $source);
        if ((string) $oldCost !== (string) $variant->cost_price && ($oldCost !== null || $variant->cost_price !== null)) {
            $this->history($variant->id, null, 'cost', $oldCost, $variant->cost_price, $userId, $source);
        }

        if ($queueSync) {
            $fields = array_merge($changed, $contentChanged ? ['content'] : [], $variant->wasRecentlyCreated ? ['content', 'price', 'stock_status'] : []);
            $this->sync->queue($variant->id, $fields);
        }

        return $variant;
    }

    private $lists = null;

    /**
     * Set availability for many variants at once (Out of Stock tab, bulk).
     *
     * @param  list<int>  $variantIds
     * @return int how many changed
     */
    public function setAvailability(array $variantIds, string $status, ?int $userId, ?string $expectedDate = null, ?string $note = null, string $source = 'manual'): int
    {
        $changed = 0;

        DB::transaction(function () use ($variantIds, $status, $userId, $expectedDate, $note, $source, &$changed) {
            $variants = ProductVariant::whereIn('id', $variantIds)->lockForUpdate()->get();

            foreach ($variants as $variant) {
                if ($variant->availability_status === $status && ! $expectedDate) {
                    continue;
                }
                $from = $variant->availability_status;
                $marking = $status !== 'in_stock';

                $variant->update([
                    'availability_status' => $status,
                    'availability_source' => $source, // 'stock': the shelf count put it there
                    'oos_marked_by' => $marking ? $userId : null,
                    'oos_marked_at' => $marking ? now() : null,
                    'expected_restock_date' => $marking ? $expectedDate : null,
                    // Reminder: the expected date, or a week after marking.
                    'oos_review_at' => $marking ? ($expectedDate ? now()->parse($expectedDate)->startOfDay() : now()->addDays(7)) : null,
                    'backorder_taken_qty' => $status === 'backorder' ? $variant->backorder_taken_qty : 0,
                ]);

                if ($from !== $status) {
                    DB::table('availability_events')->insert([
                        'variant_id' => $variant->id, 'from_status' => $from, 'to_status' => $status,
                        'source' => $source, 'user_id' => $userId, 'note' => $note, 'created_at' => now(),
                    ]);
                    $this->sync->queue($variant->id, ['stock_status']);
                    app(\App\Services\Orders\AvailabilityEffects::class)->changed($variant->id, $from, $status);
                    $changed++;
                }
            }
        });

        return $changed;
    }

    /** @return list<string> sync fields that changed */
    private function savePrices(ProductVariant $variant, array $prices, $lists, ?int $userId, string $source): array
    {
        $existing = DB::table('variant_prices')->where('variant_id', $variant->id)->get()->keyBy('price_list_id');
        $changed = [];

        foreach ($prices as $listKey => $p) {
            if (! isset($lists[$listKey]) || ($p['regular'] ?? '') === '' || $p['regular'] === null) {
                continue;
            }
            $listId = $lists[$listKey];
            $regular = round((float) $p['regular'], 2);
            $sale = isset($p['sale']) && $p['sale'] !== '' && $p['sale'] !== null ? round((float) $p['sale'], 2) : null;
            $old = $existing[$listId] ?? null;

            if ($old && (float) $old->regular_price === $regular && ($old->sale_price === null ? null : (float) $old->sale_price) === $sale) {
                continue;
            }

            DB::table('variant_prices')->updateOrInsert(
                ['variant_id' => $variant->id, 'price_list_id' => $listId],
                ['regular_price' => $regular, 'sale_price' => $sale, 'updated_at' => now()],
            );

            if (! $old || (float) $old->regular_price !== $regular) {
                $this->history($variant->id, $listId, 'regular', $old?->regular_price, $regular, $userId, $source);
            }
            if (($old?->sale_price === null ? null : (float) $old->sale_price) !== $sale) {
                $this->history($variant->id, $listId, 'sale', $old?->sale_price, $sale, $userId, $source);
            }
            if ($listKey === 'online') {
                $changed[] = 'price';
            }
        }

        return $changed;
    }

    private function history(int $variantId, ?int $listId, string $field, $old, $new, ?int $userId, string $source): void
    {
        DB::table('price_history')->insert([
            'variant_id' => $variantId, 'price_list_id' => $listId, 'field' => $field,
            'old_value' => $old, 'new_value' => $new, 'user_id' => $userId, 'source' => $source, 'created_at' => now(),
        ]);
    }

    private function uniqueSlug(string $base, ?int $ignoreId): string
    {
        $slug = Str::slug($base) ?: 'product';
        $candidate = $slug;
        for ($n = 2; DB::table('products')->where('slug', $candidate)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $n++) {
            $candidate = "{$slug}-{$n}";
        }

        return $candidate;
    }
}
