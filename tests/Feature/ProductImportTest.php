<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        (new CatalogSeeder)->run();
        $this->actingAs($this->owner());
    }

    private function import(): int
    {
        $file = new UploadedFile(base_path('tests/fixtures/woo-export.csv'), 'woo-export.csv', 'text/csv', null, true);
        $this->post('/products/import', ['file' => $file])->assertRedirect();
        $id = (int) DB::table('product_imports')->max('id');

        do {
            $state = $this->postJson("/products/import/{$id}/step")->assertOk()->json();
        } while (in_array($state['status'], ['pending', 'running'], true));

        return $id;
    }

    public function test_woocommerce_export_is_imported_with_variants_seo_categories_and_links(): void
    {
        $id = $this->import();
        $import = DB::table('product_imports')->find($id);

        $this->assertSame('done', $import->status);
        $this->assertSame(6, (int) $import->rows_total);
        $this->assertSame(4, (int) $import->created_count);     // cashew, ajwa, 2 ajwa variations
        $this->assertSame(2, (int) $import->skipped_count);     // grouped + orphan
        $this->assertStringContainsString('Parent product id:999 not found', $import->errors);

        $cashew = Product::firstWhere('external_id', '101');
        $this->assertSame('Buy Cashew 250g', $cashew->seo_title);
        $this->assertSame('Nuts', $cashew->category->name);
        $this->assertSame('Dry Fruits', $cashew->category->parent->name);
        $this->assertSame('https://example.com/cashew.jpg', $cashew->image_url);
        $this->assertSame(['https://example.com/cashew-2.jpg'], $cashew->gallery);
        $v = $cashew->variants->first();
        $this->assertSame('CASH-250', $v->sku);
        $this->assertSame(270, $v->weight_g);

        $ajwa = Product::firstWhere('external_id', '200');
        $this->assertSame(['500g', '1kg'], $ajwa->variants->pluck('name')->all());
        $kg = ProductVariant::firstWhere('sku', 'AJWA-1000');
        $this->assertSame('out_of_stock', $kg->availability_status);
        $this->assertDatabaseHas('channel_product_links', ['variant_id' => $kg->id, 'external_product_id' => '200', 'external_variant_id' => '202']);
        $this->assertDatabaseHas('price_history', ['variant_id' => $kg->id, 'field' => 'sale', 'source' => 'import']);

        // Importing from the website never queues a push back to it.
        $this->assertSame(0, DB::table('channel_sync_jobs')->count());
    }

    public function test_reimport_updates_instead_of_duplicating_and_keeps_iqs_units(): void
    {
        $this->import();
        ProductVariant::where('sku', 'AJWA-500')->update(['unit' => 'g', 'pack_qty' => 500]);

        $second = DB::table('product_imports')->find($this->import());

        $this->assertSame(0, (int) $second->created_count);
        $this->assertSame(2, Product::count());
        $this->assertSame(3, ProductVariant::count());
        $this->assertSame('g', ProductVariant::firstWhere('sku', 'AJWA-500')->unit);
    }

    public function test_non_woocommerce_file_fails_clearly(): void
    {
        $file = UploadedFile::fake()->createWithContent('x.csv', "foo,bar\n1,2\n");
        $this->post('/products/import', ['file' => $file]);
        $id = (int) DB::table('product_imports')->max('id');

        $this->postJson("/products/import/{$id}/step")->assertJson(['status' => 'failed']);
    }
}
