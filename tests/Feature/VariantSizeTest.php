<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VariantSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_size_set_by_hand_stays_and_none_counts_in_pieces(): void
    {
        (new CatalogSeeder)->run();
        Product::factory()->withVariant(300, ['sku' => 'GIFT-1'])->create(['name' => 'Gift box']);
        $v = ProductVariant::firstWhere('sku', 'GIFT-1');
        $this->assertNull($v->size_unit); // nothing in the name

        $service = app(ProductService::class);
        $row = ['sku' => 'GIFT-1', 'name' => 'Default', 'unit' => 'pcs', 'pack_qty' => 1];
        $service->saveVariant($v->product, $v, $row + ['size_unit' => 'g', 'size_qty' => 750], null);
        $this->assertSame([750.0, 'g'], [(float) $v->fresh()->size_qty, $v->fresh()->size_unit]);

        // An import (no size sent) keeps it; "pcs" says it is counted in pieces (not "size not set").
        $service->saveVariant($v->product, $v->fresh(), $row, null, 'import');
        $this->assertSame('g', $v->fresh()->size_unit);
        $service->saveVariant($v->product, $v->fresh(), $row + ['size_unit' => 'none'], null);
        $this->assertSame([null, 'pc'], [$v->fresh()->size_qty, $v->fresh()->size_unit]);
        $service->saveVariant($v->product, $v->fresh(), $row, null, 'import');
        $this->assertSame('pc', $v->fresh()->size_unit);
    }
}
