<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Services\Catalog\Store\WooApi;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pulls products straight from the website through its REST API, page by
 * page, and hands each one to the same import as the CSV (so a product
 * looks the same whichever way it came). Also applies a single product from
 * a product webhook. The website is the master: what it says replaces what
 * is here (names, prices, stock status, images, SEO); unit, pack size,
 * barcode and cost price stay as set in IQS.
 */
class WooApiImporter
{
    private const PER_PAGE = 50;

    private const MAX_ERRORS = 200;

    private ?array $categoryPaths = null;

    private ?string $weightUnit = null;

    public function __construct(private WooApi $api, private WooCsvImporter $importer) {}

    /** A new pull: one import row that step() fills. @return int import id */
    public function start(?int $userId): int
    {
        return DB::table('product_imports')->insertGetId([
            'user_id' => $userId, 'source' => 'api', 'file_name' => __('Website API'), 'path' => '', 'status' => 'pending',
            'rows_total' => $this->api->productCount(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Fetch and import the next page of products (and the variations of each variable one). */
    public function step(int $importId): object
    {
        $import = DB::table('product_imports')->where('id', $importId)->first();
        if (! $import || in_array($import->status, ['done', 'failed'], true)) {
            return $import;
        }
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $errors = $import->errors ? json_decode($import->errors, true) : [];
        $page = (int) $import->cursor + 1;

        try {
            $products = $this->api->get('products', ['per_page' => self::PER_PAGE, 'page' => $page, 'status' => 'any', 'orderby' => 'id', 'order' => 'asc'])->json() ?: [];
        } catch (Throwable $e) {
            DB::table('product_imports')->where('id', $importId)->update(['status' => 'failed', 'errors' => json_encode(array_merge($errors, [$e->getMessage()])), 'updated_at' => now()]);

            return DB::table('product_imports')->where('id', $importId)->first();
        }

        foreach ($products as $p) {
            foreach ($this->rowsFor($p) as $row) {
                try {
                    $counts[DB::transaction(fn () => $this->importer->importRow($row, $import->user_id))]++;
                } catch (Throwable $e) {
                    $counts['skipped']++;
                    if (count($errors) < self::MAX_ERRORS) {
                        $errors[] = ($row['SKU'] ?: $row['Name'] ?? '?').': '.$e->getMessage();
                    }
                }
            }
        }

        $finished = count($products) < self::PER_PAGE;
        DB::table('product_imports')->where('id', $importId)->update([
            'status' => $finished ? 'done' : 'running', 'cursor' => $page,
            'rows_done' => $import->rows_done + count($products),
            'created_count' => $import->created_count + $counts['created'],
            'updated_count' => $import->updated_count + $counts['updated'],
            'skipped_count' => $import->skipped_count + $counts['skipped'],
            'errors' => json_encode($errors), 'finished_at' => $finished ? now() : null, 'updated_at' => now(),
        ]);

        return DB::table('product_imports')->where('id', $importId)->first();
    }

    /**
     * One product as the website sent it in a webhook (created / updated).
     * Variable: its variations are fetched through the API when keys are saved.
     */
    public function applyProduct(array $p, ?int $userId = null): string
    {
        $result = 'skipped';
        foreach ($this->rowsFor($p) as $row) {
            $r = DB::transaction(fn () => $this->importer->importRow($row, $userId));
            $result = $result === 'skipped' ? $r : $result;
        }

        return $result;
    }

    /** Deleted on the website: put in the trash here (it comes back by itself if the website sends it again). */
    public function removeProduct(string $externalId): bool
    {
        $product = Product::where('external_id', $externalId)->first();
        if (! $product) {
            return false;
        }
        $product->variants()->update(['is_active' => false]);
        $product->delete();

        return true;
    }

    /**
     * The product (and its variations) as rows with the CSV export's column
     * names, so the one importer serves both ways in.
     *
     * @return list<array<string, string>>
     */
    private function rowsFor(array $p): array
    {
        $type = (string) ($p['type'] ?? 'simple');
        $rows = [$this->row($p, $type, null)];
        if ($type === 'variable' && ! empty($p['id'])) {
            $variations = [];
            if ($this->api->configured()) {
                try {
                    $variations = $this->api->get("products/{$p['id']}/variations", ['per_page' => 100, 'status' => 'any'])->json() ?: [];
                } catch (Throwable) {
                    $variations = [];
                }
            }
            foreach ($variations as $i => $v) {
                $rows[] = $this->row($v, 'variation', (string) $p['id'], $i);
            }
        }

        return $rows;
    }

    private function row(array $p, string $type, ?string $parentId, int $position = 0): array
    {
        $status = (string) ($p['status'] ?? 'publish');
        $images = array_values(array_filter(array_map(fn ($i) => $i['src'] ?? null, $p['images'] ?? (isset($p['image']['src']) ? [$p['image']] : []))));
        $row = [
            'ID' => (string) ($p['id'] ?? ''),
            'Type' => $type,
            'SKU' => (string) ($p['sku'] ?? ''),
            'Name' => (string) ($p['name'] ?? ''),
            'Published' => $status === 'publish' ? '1' : ($status === 'trash' ? '-1' : '0'),
            'Regular price' => (string) ($p['regular_price'] ?? ''),
            'Sale price' => (string) ($p['sale_price'] ?? ''),
            'Categories' => $this->categoryPath($p['categories'] ?? []),
            'Images' => implode(',', $images),
            'Short description' => (string) ($p['short_description'] ?? ''),
            'Description' => (string) ($p['description'] ?? ''),
            'Tags' => implode(',', array_map(fn ($t) => $t['name'] ?? '', $p['tags'] ?? [])),
            'Weight (kg)' => $this->weightKg($p['weight'] ?? ''),
            'In stock?' => match ((string) ($p['stock_status'] ?? 'instock')) { 'outofstock' => '0', 'onbackorder' => 'backorder', default => '1' },
            'Parent' => $parentId ? 'id:'.$parentId : '',
            'Position' => (string) ($p['menu_order'] ?? $position),
        ];
        foreach (($p['attributes'] ?? []) as $i => $a) {
            if ($i < 5) {
                $row['Attribute '.($i + 1).' value(s)'] = (string) ($a['option'] ?? implode(', ', $a['options'] ?? []));
            }
        }
        foreach (($p['meta_data'] ?? []) as $m) {
            if (isset($m['key']) && is_scalar($m['value'] ?? null)) {
                $row['Meta: '.$m['key']] = (string) $m['value'];
            }
        }

        return $row;
    }

    /** "Dry Fruits > Dates" for the product's first category, from the website's category tree (read once per request). */
    private function categoryPath(array $categories): string
    {
        $first = $categories[0] ?? null;
        if (! $first) {
            return '';
        }
        if ($this->categoryPaths === null) {
            $this->categoryPaths = [];
            try {
                $all = collect($this->api->configured() ? $this->api->get('products/categories', ['per_page' => 100])->json() : [])->keyBy('id');
                foreach ($all as $id => $c) {
                    $path = [];
                    for ($cur = $c, $guard = 0; $cur && $guard < 6; $cur = $all[$cur['parent'] ?? 0] ?? null, $guard++) {
                        array_unshift($path, html_entity_decode((string) $cur['name']));
                    }
                    $this->categoryPaths[$id] = implode(' > ', $path);
                }
            } catch (Throwable) {
                // no tree: the category name alone
            }
        }

        return $this->categoryPaths[$first['id'] ?? 0] ?? html_entity_decode((string) ($first['name'] ?? ''));
    }

    private function weightKg(string|int|float $weight): string
    {
        if ($weight === '' || ! is_numeric($weight)) {
            return '';
        }
        if ($this->weightUnit === null) {
            try {
                $this->weightUnit = $this->api->configured() ? (string) ($this->api->get('settings/products/woocommerce_weight_unit')->json('value') ?? 'kg') : 'kg';
            } catch (Throwable) {
                $this->weightUnit = 'kg';
            }
        }

        return (string) match ($this->weightUnit) { 'g' => $weight / 1000, 'lbs' => $weight * 0.4536, 'oz' => $weight * 0.02835, default => $weight };
    }
}
