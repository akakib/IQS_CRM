<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\ChannelSync;
use App\Services\Catalog\Store\FakeStoreDriver;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        $this->owner = $this->owner();
        $this->actingAs($this->owner);
        FakeStoreDriver::$sent = [];
    }

    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'name' => 'Medjool Dates',
            'status' => 'active',
            'base_unit' => 'g',
            'description' => '<p>Soft dates</p>',
            'seo_title' => 'Buy Medjool Dates',
            'seo_description' => 'Fresh medjool dates delivered.',
            'variants' => [
                ['name' => '500 g', 'sku' => 'MED-500', 'unit' => 'g', 'pack_qty' => 500, 'weight_g' => 550, 'cost_price' => 600,
                    'prices' => ['online' => ['regular' => 900, 'sale' => 850], 'shop' => ['regular' => 880], 'b2b' => ['regular' => 750]]],
                ['name' => '1 kg', 'sku' => 'MED-1000', 'unit' => 'g', 'pack_qty' => 1000, 'cost_price' => 1150,
                    'prices' => ['online' => ['regular' => 1700]]],
            ],
        ], $override);
    }

    public function test_product_with_variants_three_price_lists_and_seo_is_created(): void
    {
        $this->post('/products', $this->payload())->assertRedirect();

        $product = Product::firstWhere('name', 'Medjool Dates');
        $this->assertSame('medjool-dates', $product->slug);
        $this->assertSame('Buy Medjool Dates', $product->seo_title);
        $this->assertCount(2, $product->variants);

        $v = $product->variants->first();
        $this->assertSame('MED-500', $v->sku);
        $this->assertTrue($v->is_default);
        $this->assertSame(3, DB::table('variant_prices')->where('variant_id', $v->id)->count());
        $this->assertSame('850.00', $v->prices()->whereHas('priceList', fn ($q) => $q->where('system_key', 'online'))->first()->effective());
        $this->assertStringContainsString('medjool dates 500 g med-500', $v->search_text);
    }

    public function test_price_changes_are_recorded_in_history(): void
    {
        $this->post('/products', $this->payload());
        $product = Product::firstWhere('name', 'Medjool Dates');
        $variants = $product->variants;

        $edit = $this->payload(['variants' => [
            ['id' => $variants[0]->id, 'prices' => ['online' => ['regular' => 950, 'sale' => '']], 'cost_price' => 620],
            ['id' => $variants[1]->id],
        ]]);
        $this->put("/products/{$product->id}", $edit)->assertSessionHasNoErrors();

        $history = DB::table('price_history')->where('variant_id', $variants[0]->id)->orderByDesc('id')->get();
        $this->assertTrue($history->contains(fn ($h) => $h->field === 'regular' && (float) $h->old_value === 900.0 && (float) $h->new_value === 950.0));
        $this->assertTrue($history->contains(fn ($h) => $h->field === 'sale' && (float) $h->old_value === 850.0 && $h->new_value === null));
        $this->assertTrue($history->contains(fn ($h) => $h->field === 'cost' && (float) $h->new_value === 620.0));
    }

    public function test_removed_variant_is_soft_deleted(): void
    {
        $this->post('/products', $this->payload());
        $product = Product::firstWhere('name', 'Medjool Dates');
        $keep = $product->variants[0];

        $single = $this->payload();
        $single['variants'] = [['id' => $keep->id] + $this->payload()['variants'][0]];
        $this->put("/products/{$product->id}", $single)->assertSessionHasNoErrors();

        $this->assertSame(1, $product->variants()->count());
        $this->assertSoftDeleted('product_variants', ['sku' => 'MED-1000']);
    }

    public function test_validation_rejects_duplicate_sku_and_sale_above_regular(): void
    {
        Product::factory()->withVariant(100, ['sku' => 'TAKEN'])->create();

        $bad = $this->payload();
        $bad['variants'][0]['sku'] = 'TAKEN';
        $bad['variants'][1]['prices']['online'] = ['regular' => 100, 'sale' => 150];

        $this->post('/products', $bad)->assertSessionHasErrors(['variants.0.sku', 'variants.1.prices.online.sale']);
    }

    public function test_role_without_cost_cannot_change_cost(): void
    {
        $this->post('/products', $this->payload());
        $product = Product::firstWhere('name', 'Medjool Dates');
        $v = $product->variants[0];

        $editor = User::factory()->create();
        $editor->roles()->attach($this->role(['products.view', 'products.edit'], ['cost_price'])->id);
        app(\App\Services\PermissionService::class)->bump();

        $payload = $this->payload();
        $payload['variants'] = [['id' => $v->id, 'cost_price' => 1] + $payload['variants'][0], ['id' => $product->variants[1]->id] + $payload['variants'][1]];
        $this->actingAs($editor)->put("/products/{$product->id}", $payload)->assertSessionHasNoErrors();

        $this->assertSame('600.00', $v->fresh()->cost_price);
        $this->actingAs($editor)->get("/products/{$product->id}/edit")->assertOk()->assertDontSee('Cost price');
    }

    public function test_search_returns_at_most_twenty_with_exact_sku_first(): void
    {
        foreach (range(1, 25) as $i) {
            Product::factory()->withVariant(100 + $i, ['sku' => "ALM-{$i}"])->create(['name' => "Almond pack {$i}"]);
        }
        Product::factory()->withVariant(50, ['sku' => 'X1', 'availability_status' => 'out_of_stock'])->create(['name' => 'Cashew']);

        $this->getJson('/products/search?q=almond')->assertOk()->assertJsonCount(20);
        $this->getJson('/products/search?q=alm-7')->assertJsonPath('0.sub', fn ($s) => str_starts_with($s, 'ALM-7'));
        $this->getJson('/products/search?q=cashew')->assertJsonPath('0.sellable', false)->assertJsonPath('0.sub', fn ($s) => str_contains($s, 'OUT OF STOCK'));
    }

    public function test_index_lists_filters_and_stays_in_query_budget(): void
    {
        foreach (range(1, 30) as $i) {
            Product::factory()->withVariant(100 + $i)->create();
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->get('/products?per_page=25')->assertOk()->assertSee('data-view="table"', false);
        $this->assertLessThanOrEqual(10, count(\Illuminate\Support\Facades\DB::getQueryLog()));
    }

    public function test_availability_change_logs_event_and_queues_website_sync(): void
    {
        Product::factory()->withVariant(100, ['sku' => 'PIS-1'])->create(['name' => 'Pistachio']);
        $v = ProductVariant::firstWhere('sku', 'PIS-1');
        DB::table('channel_product_links')->insert(['variant_id' => $v->id, 'channel' => 'woocommerce', 'external_product_id' => '77', 'created_at' => now(), 'updated_at' => now()]);

        $this->post('/products/availability', ['ids' => [$v->id], 'status' => 'out_of_stock', 'expected_restock_date' => now()->addDays(3)->toDateString()])
            ->assertSessionHas('success');

        $v->refresh();
        $this->assertSame('out_of_stock', $v->availability_status);
        $this->assertSame($this->owner->id, $v->oos_marked_by);
        $this->assertDatabaseHas('availability_events', ['variant_id' => $v->id, 'from_status' => 'in_stock', 'to_status' => 'out_of_stock']);

        app(ChannelSync::class)->run();
        $this->assertSame('outofstock', FakeStoreDriver::$sent[0]['stock_status']);
        $this->assertSame('77', FakeStoreDriver::$sent[0]['external_product_id']);
    }

    public function test_backorder_is_published_as_in_stock_and_ten_edits_send_once(): void
    {
        Product::factory()->withVariant(100, ['sku' => 'FIG-1'])->create(['name' => 'Fig']);
        $v = ProductVariant::firstWhere('sku', 'FIG-1');
        DB::table('channel_product_links')->insert(['variant_id' => $v->id, 'channel' => 'woocommerce', 'external_product_id' => '9', 'created_at' => now(), 'updated_at' => now()]);

        $sync = app(ChannelSync::class);
        foreach (range(1, 10) as $i) {
            $sync->queue($v->id, ['price']);
        }
        app(\App\Services\Catalog\ProductService::class)->setAvailability([$v->id], 'backorder', $this->owner->id);

        $this->assertSame(1, DB::table('channel_sync_jobs')->where('status', 'queued')->count());
        $sync->run();
        $this->assertCount(1, FakeStoreDriver::$sent);
        $this->assertSame('instock', FakeStoreDriver::$sent[0]['stock_status']);
        $this->assertEquals(100.0, (float) FakeStoreDriver::$sent[0]['regular_price']);
    }

    public function test_sync_retries_then_alerts_after_max_attempts(): void
    {
        $this->app->instance(\App\Services\Catalog\Store\StoreDriver::class, new class implements \App\Services\Catalog\Store\StoreDriver
        {
            public function name(): string { return 'broken'; }

            public function pushVariant(object $link, array $payload): void { throw new \RuntimeException('HTTP 500'); }
        });
        $this->app->forgetInstance(ChannelSync::class);
        \Illuminate\Support\Facades\Artisan::call('notifications:sync');

        Product::factory()->withVariant(100, ['sku' => 'BAD-1'])->create();
        $v = ProductVariant::firstWhere('sku', 'BAD-1');
        DB::table('channel_product_links')->insert(['variant_id' => $v->id, 'channel' => 'woocommerce', 'external_product_id' => '1', 'created_at' => now(), 'updated_at' => now()]);
        app(ChannelSync::class)->queue($v->id, ['price']);

        foreach (range(1, ChannelSync::MAX_ATTEMPTS) as $i) {
            $this->travel(1)->hour();
            app(ChannelSync::class)->run();
        }

        $this->assertDatabaseHas('channel_sync_jobs', ['variant_id' => $v->id, 'status' => 'failed', 'attempts' => ChannelSync::MAX_ATTEMPTS]);
    }

    public function test_unlinked_variant_is_not_queued_and_permissions_apply(): void
    {
        $this->post('/products', $this->payload());
        $this->assertSame(0, DB::table('channel_sync_jobs')->count());

        $viewer = User::factory()->create();
        $viewer->roles()->attach($this->role(['products.view'])->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($viewer);
        $this->get('/products')->assertOk()->assertDontSee('New product');
        $this->get('/products/create')->assertForbidden();
        $this->get('/products/availability')->assertForbidden();
    }

    public function test_categories_crud_keeps_products(): void
    {
        $this->post('/categories', ['name' => 'Dates'])->assertSessionHas('success');
        $cat = \App\Models\Category::firstWhere('name', 'Dates');
        Product::factory()->withVariant()->create(['category_id' => $cat->id]);

        $this->delete("/categories/{$cat->id}")->assertSessionHas('success');
        $this->assertSoftDeleted($cat);
        $this->assertSame(0, Product::whereNotNull('category_id')->count());
        $this->assertSame(1, Product::count());
    }
}
