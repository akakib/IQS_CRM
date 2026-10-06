<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Imports WooCommerce's standard product export CSV (Products > Export).
 * simple -> product + one variant; variable -> product; variation -> variant
 * of its parent. Matching: website id link first, then SKU, else create.
 * Imported values are NOT pushed back to the website. Processed in steps of
 * a few hundred rows so a 7,000-product file never times out a request.
 */
class WooCsvImporter
{
    private const MAX_ERRORS = 200;

    /** @var array<string, int> category path => id (per step) */
    private array $categoryCache = [];

    public function __construct(private ProductService $products) {}

    /** Process the next chunk. @return object the updated import row */
    public function step(int $importId, int $rows = 300): object
    {
        $import = DB::table('product_imports')->where('id', $importId)->first();
        if (! $import || in_array($import->status, ['done', 'failed'], true)) {
            return $import;
        }

        $path = Storage::disk('local')->path($import->path);
        $handle = fopen($path, 'rb');
        $header = $import->header ? json_decode($import->header, true) : null;

        if (! $header) {
            $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), fgetcsv($handle) ?: []);
            if (! in_array('Type', $header, true) || ! in_array('Name', $header, true)) {
                fclose($handle);
                DB::table('product_imports')->where('id', $importId)->update([
                    'status' => 'failed', 'errors' => json_encode(['This is not a WooCommerce product export (missing Type / Name columns).']), 'updated_at' => now(),
                ]);

                return DB::table('product_imports')->where('id', $importId)->first();
            }
        } else {
            fseek($handle, (int) $import->offset);
        }

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $errors = $import->errors ? json_decode($import->errors, true) : [];
        $done = 0;

        while ($done < $rows && ($line = fgetcsv($handle)) !== false) {
            if ($line === [null]) {
                continue;
            }
            $done++;
            $row = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));

            try {
                $result = DB::transaction(fn () => $this->importRow($row, $import->user_id));
                $counts[$result]++;
            } catch (Throwable $e) {
                $counts['skipped']++;
                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = 'Row '.($import->rows_done + $done).' ('.($row['SKU'] ?: $row['Name'] ?? '?').'): '.$e->getMessage();
                }
            }
        }

        $finished = feof($handle) || $done < $rows;
        $offset = ftell($handle);
        fclose($handle);

        DB::table('product_imports')->where('id', $importId)->update([
            'status' => $finished ? 'done' : 'running',
            'header' => json_encode($header),
            'offset' => $offset,
            'rows_done' => $import->rows_done + $done,
            'created_count' => $import->created_count + $counts['created'],
            'updated_count' => $import->updated_count + $counts['updated'],
            'skipped_count' => $import->skipped_count + $counts['skipped'],
            'errors' => json_encode($errors),
            'finished_at' => $finished ? now() : null,
            'updated_at' => now(),
        ]);

        return DB::table('product_imports')->where('id', $importId)->first();
    }

    /** Count data rows once, for the progress bar. */
    public function countRows(string $path): int
    {
        $handle = fopen($path, 'rb');
        fgetcsv($handle);
        $n = 0;
        while (($line = fgetcsv($handle)) !== false) {
            $n += $line !== [null] ? 1 : 0;
        }
        fclose($handle);

        return $n;
    }

    /** One product row (CSV columns; the API importer builds the same shape). @return string created | updated | skipped */
    public function importRow(array $row, ?int $userId): string
    {
        $type = strtolower(trim($row['Type'] ?? ''));
        $id = trim((string) ($row['ID'] ?? ''));

        return match (true) {
            str_contains($type, 'variation') => $this->importVariation($row, $id, $userId),
            str_contains($type, 'variable') => $this->importParent($row, $id, $userId, withVariant: false),
            $type === 'simple' || str_contains($type, 'simple') => $this->importParent($row, $id, $userId, withVariant: true),
            default => 'skipped', // grouped / external products are not sold through orders
        };
    }

    private function importParent(array $row, string $id, ?int $userId, bool $withVariant): string
    {
        $sku = trim((string) ($row['SKU'] ?? ''));
        $product = ($id !== '' ? Product::withTrashed()->where('external_id', $id)->first() : null)
            ?? ($withVariant && $sku !== '' ? ProductVariant::where('sku', $sku)->first()?->product : null);
        $existed = (bool) $product;
        $product ??= new Product;
        if ($product->trashed()) {
            $product->restore();
        }

        $images = array_values(array_filter(array_map('trim', explode(',', (string) ($row['Images'] ?? '')))));
        $this->products->saveProduct($product, [
            'name' => trim($row['Name']),
            'slug' => $product->slug,
            'category_id' => $this->category((string) ($row['Categories'] ?? '')),
            'short_description' => $row['Short description'] ?? null ?: null,
            'description' => $row['Description'] ?? null ?: null,
            'seo_title' => $this->meta($row, ['rank_math_title', '_yoast_wpseo_title']),
            'seo_description' => $this->meta($row, ['rank_math_description', '_yoast_wpseo_metadesc']),
            'focus_keyword' => $this->meta($row, ['rank_math_focus_keyword', '_yoast_wpseo_focuskw']),
            'status' => ($row['Published'] ?? '1') === '1' ? 'active' : 'draft',
            'image_url' => $images[0] ?? $product->image_url,
            'gallery' => array_slice($images, 1) ?: null,
            'tags' => ($row['Tags'] ?? '') ?: null,
            'base_unit' => $product->base_unit ?? 'pcs',
        ], $userId);
        if ($id !== '' && $product->external_id !== $id) {
            $product->forceFill(['external_id' => $id])->save();
        }

        if ($withVariant) {
            $variant = $product->variants()->first() ?? new ProductVariant;
            $this->saveVariant($product, $variant, $row, $sku ?: 'WC-'.$id, 'Default', $id, null, $userId, 0);
        }

        return $existed ? 'updated' : 'created';
    }

    private function importVariation(array $row, string $id, ?int $userId): string
    {
        $parentRef = trim((string) ($row['Parent'] ?? ''));
        $parent = str_starts_with($parentRef, 'id:')
            ? Product::where('external_id', substr($parentRef, 3))->first()
            : ProductVariant::where('sku', $parentRef)->first()?->product;

        if (! $parent) {
            throw new \RuntimeException("Parent product {$parentRef} not found (the parent row must come before its variations).");
        }

        $sku = trim((string) ($row['SKU'] ?? '')) ?: 'WC-'.$id;
        $link = DB::table('channel_product_links')->where('channel', 'woocommerce')->where('external_variant_id', $id)->first();
        $variant = ($link ? ProductVariant::find($link->variant_id) : null) ?? ProductVariant::where('sku', $sku)->first();
        $existed = (bool) $variant;

        // Variation name = its attribute values ("500g", "Gift box").
        $name = collect(range(1, 5))->map(fn ($n) => trim((string) ($row["Attribute {$n} value(s)"] ?? '')))->filter()->join(' / ')
            ?: trim(Str::after($row['Name'] ?? '', ' - ')) ?: 'Variant';

        $this->saveVariant($parent, $variant ?? new ProductVariant, $row, $sku, $name, (string) $parent->external_id, $id, $userId, (int) ($row['Position'] ?? 0));

        return $existed ? 'updated' : 'created';
    }

    private function saveVariant(Product $product, ProductVariant $variant, array $row, string $sku, string $name, ?string $extProduct, ?string $extVariant, ?int $userId, int $order): void
    {
        $weightKg = (float) str_replace(',', '.', (string) ($row['Weight (kg)'] ?? ''));
        $inStock = strtolower(trim((string) ($row['In stock?'] ?? '1')));
        $cost = $this->meta($row, ['_wc_cog_cost', '_purchase_price', 'purchase_price']);

        $data = [
            'sku' => $sku,
            'name' => $name,
            'unit' => 'pcs',
            'pack_qty' => 1,
            'weight_g' => $weightKg > 0 ? (int) round($weightKg * 1000) : null,
            'is_active' => ($row['Published'] ?? '1') !== '-1',
            'sort_order' => $order,
            'availability_status' => match ($inStock) { '0' => 'out_of_stock', 'backorder' => 'backorder', default => 'in_stock' },
            'prices' => ['online' => ['regular' => $row['Regular price'] ?? '', 'sale' => $row['Sale price'] ?? '']],
        ];
        if ($cost !== null && is_numeric($cost)) {
            $data['cost_price'] = $cost;
        }
        if ($variant->exists) {
            $data['unit'] = $variant->unit;          // set in IQS, not in Woo
            $data['pack_qty'] = $variant->pack_qty;
            $data['barcode'] = $variant->barcode;
        }

        $existed = $variant->exists;
        $before = $existed ? $variant->availability_status : null;
        $variant = $this->products->saveVariant($product, $variant, $data, $userId, 'import', queueSync: false);
        // The website is the master for stock status: a change goes the normal way (and releases waiting orders).
        if ($existed && $before !== $data['availability_status']) {
            $this->products->setAvailability([$variant->id], $data['availability_status'], $userId);
        }

        if ($extProduct) {
            DB::table('channel_product_links')->updateOrInsert(
                ['channel' => 'woocommerce', 'external_product_id' => $extProduct, 'external_variant_id' => $extVariant],
                ['variant_id' => $variant->id, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    /** "Dry Fruits > Dates, Gifts" -> id of "Dates" (first path), creating the tree. */
    private function category(string $value): ?int
    {
        $path = trim(explode(',', $value)[0] ?? '');
        if ($path === '') {
            return null;
        }
        if (isset($this->categoryCache[$path])) {
            return $this->categoryCache[$path];
        }

        $parentId = null;
        foreach (array_map('trim', explode('>', $path)) as $name) {
            $name = html_entity_decode($name);
            $category = Category::where('name', $name)->where('parent_id', $parentId)->first()
                ?? Category::create(['name' => $name, 'parent_id' => $parentId, 'slug' => $this->categorySlug($name)]);
            $parentId = $category->id;
        }

        return $this->categoryCache[$path] = $parentId;
    }

    private function categorySlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        for ($n = 2; Category::withTrashed()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function meta(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row["Meta: {$key}"] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
