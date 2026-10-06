<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WebsiteAccount;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Website API keys in Settings, a connection check, pulling products through the API, and product webhooks. */
class WebsiteApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new \Database\Seeders\OrderConfigSeeder)->run(); // stock changes release waiting orders: statuses must exist
        \App\Models\OrderStatus::forget();
        config(['store.woocommerce.webhook_secret' => self::SECRET]);
    }

    /** The website's answers: 2 products (a simple one and a variable one with 2 variations), the category tree, the weight unit. */
    private function fakeWebsite(array $overrides = []): void
    {
        $products = [
            ['id' => 101, 'type' => 'simple', 'sku' => 'DATE-500', 'name' => 'Ajwa Dates 500g', 'status' => 'publish', 'regular_price' => '900', 'sale_price' => '850',
                'categories' => [['id' => 5, 'name' => 'Dates']], 'images' => [['src' => 'https://x/img1.jpg'], ['src' => 'https://x/img2.jpg']], 'tags' => [['name' => 'premium']],
                'weight' => '500', 'stock_status' => 'instock', 'short_description' => 'Short', 'description' => 'Long',
                'meta_data' => [['key' => 'rank_math_title', 'value' => 'Ajwa Dates | Shop']]],
            ['id' => 102, 'type' => 'variable', 'sku' => 'NUT', 'name' => 'Mixed Nuts', 'status' => 'publish', 'categories' => [['id' => 7, 'name' => 'Nuts']],
                'images' => [['src' => 'https://x/nuts.jpg']], 'weight' => '', 'stock_status' => 'instock', 'meta_data' => []],
        ];
        $variations = [
            ['id' => 201, 'sku' => 'NUT-250', 'status' => 'publish', 'regular_price' => '450', 'sale_price' => '', 'weight' => '250', 'stock_status' => 'instock', 'menu_order' => 1, 'attributes' => [['name' => 'Size', 'option' => '250g']], 'image' => ['src' => 'https://x/n250.jpg']],
            ['id' => 202, 'sku' => 'NUT-500', 'status' => 'publish', 'regular_price' => '850', 'sale_price' => '', 'weight' => '500', 'stock_status' => 'outofstock', 'menu_order' => 2, 'attributes' => [['name' => 'Size', 'option' => '500g']]],
        ];
        Http::fake(array_merge([
            'shop.test/wp-json/wc/v3/products/102/variations*' => Http::response($variations),
            'shop.test/wp-json/wc/v3/products/categories*' => Http::response([['id' => 1, 'name' => 'Dry Fruits', 'parent' => 0], ['id' => 5, 'name' => 'Dates', 'parent' => 1], ['id' => 7, 'name' => 'Nuts', 'parent' => 1]]),
            'shop.test/wp-json/wc/v3/settings/products/woocommerce_weight_unit' => Http::response(['value' => 'g']),
            'shop.test/wp-json/wc/v3/products*' => Http::response($products, 200, ['X-WP-Total' => '2']),
        ], $overrides));
    }

    public function test_keys_are_saved_encrypted_checked_and_products_are_pulled_and_pulled_again_without_doubles(): void
    {
        $this->actingAs($this->owner());
        $this->post('/settings/website-api', ['name' => 'Shop', 'url' => 'https://shop.test/', 'consumer_key' => 'ck_1234567890abcdef', 'consumer_secret' => 'cs_abcdef1234567890'])->assertSessionHas('success');
        $account = WebsiteAccount::firstOrFail();
        $this->assertSame('https://shop.test', $account->url);
        $this->assertSame('ck_1234567890abcdef', $account->consumer_key);
        $this->assertNotSame('ck_1234567890abcdef', DB::table('website_accounts')->value('consumer_key'));
        $this->get('/settings/integrations')->assertOk()->assertSee('ck_1…cdef')->assertDontSee('ck_1234567890abcdef')->assertDontSee('cs_abcdef');

        $this->fakeWebsite();
        $this->post("/settings/website-api/{$account->id}/check")->assertSessionHas('success', 'Connected. 2 products on the website.');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'shop.test/wp-json/wc/v3/products') && $r->hasHeader('Authorization'));

        // Pull: the import page steps through the pages.
        $this->post('/settings/website-api/pull')->assertRedirect();
        $import = DB::table('product_imports')->where('source', 'api')->first();
        $this->assertSame(2, (int) $import->rows_total);
        $this->post("/products/import/{$import->id}/step")->assertOk()->assertJson(['status' => 'done', 'rows_done' => 2, 'created' => 4, 'skipped' => 0]);

        $dates = Product::where('external_id', '101')->firstOrFail();
        $this->assertSame('Ajwa Dates 500g', $dates->name);
        $this->assertSame('Ajwa Dates | Shop', $dates->seo_title);
        $this->assertSame('https://x/img1.jpg', $dates->image_url);
        $this->assertSame('Dates', $dates->category->name);
        $this->assertSame('Dry Fruits', $dates->category->parent->name); // the tree came from the website
        $v = ProductVariant::where('sku', 'DATE-500')->firstOrFail();
        $this->assertSame(500, $v->weight_g); // website weight unit g
        $this->assertSame('in_stock', $v->availability_status);
        $this->assertSame('850.00', (string) $v->prices()->first()->sale_price);

        $nuts = Product::where('external_id', '102')->firstOrFail();
        $this->assertSame(2, $nuts->variants()->count());
        $this->assertSame('out_of_stock', ProductVariant::where('sku', 'NUT-500')->value('availability_status'));
        $this->assertSame('250g', ProductVariant::where('sku', 'NUT-250')->value('name'));
        $this->assertDatabaseHas('channel_product_links', ['channel' => 'woocommerce', 'external_product_id' => '102', 'external_variant_id' => '201']);

        // Pulled again: updated, not doubled. Unit set in IQS is kept.
        $v->update(['unit' => 'g', 'pack_qty' => 500]);
        $this->post('/settings/website-api/pull');
        $again = DB::table('product_imports')->where('source', 'api')->orderByDesc('id')->first();
        $this->post("/products/import/{$again->id}/step")->assertJson(['status' => 'done', 'created' => 0, 'updated' => 4]);
        $this->assertSame(2, Product::count());
        $this->assertSame('g', $v->fresh()->unit);
    }

    public function test_product_webhooks_update_and_trash_products_here(): void
    {
        $this->fakeWebsite();
        $this->actingAs($this->owner())->post('/settings/website-api', ['name' => 'Shop', 'url' => 'https://shop.test', 'consumer_key' => 'ck_x', 'consumer_secret' => 'cs_x']);
        $send = function (array $payload, string $topic) {
            $body = json_encode($payload);

            return $this->call('POST', '/webhooks/woocommerce', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
                'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
            ], $body);
        };

        // Created on the website: here too, with its variations fetched through the API.
        $send(['id' => 102, 'type' => 'variable', 'sku' => 'NUT', 'name' => 'Mixed Nuts', 'status' => 'publish', 'categories' => [['id' => 7, 'name' => 'Nuts']], 'images' => [], 'stock_status' => 'instock'], 'product.created')->assertOk();
        $this->assertSame(2, Product::where('external_id', '102')->firstOrFail()->variants()->count());

        // Price and stock changed on the website: changed here (the website is the master).
        $send(['id' => 101, 'type' => 'simple', 'sku' => 'DATE-500', 'name' => 'Ajwa Dates 500g', 'status' => 'publish', 'regular_price' => '999', 'sale_price' => '', 'stock_status' => 'outofstock', 'categories' => [], 'images' => []], 'product.created')->assertOk();
        $send(['id' => 101, 'type' => 'simple', 'sku' => 'DATE-500', 'name' => 'Ajwa Dates 500g (new)', 'status' => 'publish', 'regular_price' => '1050', 'sale_price' => '', 'stock_status' => 'instock', 'categories' => [], 'images' => []], 'product.updated')->assertOk();
        $v = ProductVariant::where('sku', 'DATE-500')->firstOrFail();
        $this->assertSame('Ajwa Dates 500g (new)', $v->product->name);
        $this->assertSame('1050.00', (string) $v->prices()->first()->regular_price);
        $this->assertSame('in_stock', $v->availability_status);
        $this->assertSame(1, Product::where('external_id', '101')->count());

        // Deleted on the website: in the trash here, variants off; the same product sent again comes back.
        $send(['id' => 101], 'product.deleted')->assertOk();
        $this->assertNull(Product::where('external_id', '101')->first());
        $this->assertNotNull(Product::withTrashed()->where('external_id', '101')->first());
        $this->assertFalse((bool) $v->fresh()->is_active);
        $send(['id' => 101, 'type' => 'simple', 'sku' => 'DATE-500', 'name' => 'Ajwa Dates 500g', 'status' => 'publish', 'regular_price' => '1050', 'stock_status' => 'instock', 'categories' => [], 'images' => []], 'product.updated')->assertOk();
        $this->assertNotNull(Product::where('external_id', '101')->first());
        $this->assertSame(1, Product::withTrashed()->where('external_id', '101')->count());
        $this->assertSame(4, DB::table('integration_inbox')->where('external_id', 'like', 'product:101:%')->where('status', 'processed')->count());
    }
}
