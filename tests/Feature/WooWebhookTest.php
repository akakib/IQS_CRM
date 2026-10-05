<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WooWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        config(['store.woocommerce.webhook_secret' => self::SECRET]);

        Product::factory()->withVariant(560, ['sku' => 'CASH-250', 'weight_g' => 270])->create(['name' => 'Cashew']);
        $v = ProductVariant::firstWhere('sku', 'CASH-250');
        DB::table('channel_product_links')->insert(['variant_id' => $v->id, 'channel' => 'woocommerce', 'external_product_id' => '101', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'id' => 5001, 'number' => '5001', 'status' => 'processing', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
            'billing' => ['first_name' => 'Nusrat', 'last_name' => 'Jahan', 'phone' => '+8801712345678', 'address_1' => 'Flat 3B, Road 7', 'city' => 'Dhanmondi', 'state' => 'Dhaka'],
            'shipping' => [],
            'line_items' => [['product_id' => 101, 'variation_id' => 0, 'sku' => 'CASH-250', 'name' => 'Cashew 250g', 'quantity' => 2, 'subtotal' => '1120.00', 'total' => '1020.00']],
            'shipping_total' => '70.00', 'total' => '1090.00', 'customer_note' => 'Call before coming',
            'meta_data' => [['key' => '_fbp', 'value' => 'fb.1.123'], ['key' => '_wc_order_attribution_utm_source', 'value' => 'facebook']],
        ], $override);
    }

    private function send(array $payload, ?string $secret = self::SECRET, string $topic = 'order.created')
    {
        $body = json_encode($payload);

        return $this->call('POST', '/webhooks/woocommerce', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
            'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, (string) $secret, true)),
        ], $body);
    }

    public function test_signed_order_webhook_creates_an_unowned_web_order(): void
    {
        $this->send($this->payload())->assertOk()->assertJson(['status' => 'received']);

        $order = Order::firstWhere('external_ref', '5001');
        $this->assertNotNull($order);
        $this->assertSame('web', $order->channel);
        $this->assertNull($order->moderator_id);
        $this->assertSame('01712345678', $order->ship_phone);
        $this->assertSame('Nusrat Jahan', $order->ship_name);
        $this->assertSame('560.00', $order->items->first()->unit_price);
        $this->assertSame('100.00', $order->items->first()->line_discount);
        $this->assertSame('70.00', $order->delivery_charge);
        $this->assertSame('1090.00', $order->grand_total);
        $this->assertSame('fb.1.123', $order->fbp);
        $this->assertSame('facebook', $order->utm['source']);
        $this->assertSame('Call before coming', $order->customer_note);
        $this->assertDatabaseHas('integration_inbox', ['external_id' => 'order:5001', 'status' => 'processed', 'order_id' => $order->id]);
    }

    public function test_bad_signature_is_rejected_and_duplicates_are_ignored(): void
    {
        $this->send($this->payload(), 'wrong')->assertStatus(401);
        $this->assertSame(0, Order::count());

        $this->send($this->payload())->assertOk();
        $this->send($this->payload())->assertJson(['status' => 'duplicate']);
        $this->send($this->payload(), self::SECRET, 'order.updated')->assertJson(['status' => 'ignored']);
        $this->assertSame(1, Order::count());
    }

    public function test_out_of_stock_item_puts_the_order_on_hold(): void
    {
        ProductVariant::where('sku', 'CASH-250')->update(['availability_status' => 'out_of_stock']);

        $this->send($this->payload())->assertOk();

        $order = Order::firstWhere('external_ref', '5001');
        $this->assertSame('hold', OrderStatus::map()[$order->status_id]['key']);
        $this->assertSame('stock_out', DB::table('status_reasons')->where('id', $order->hold_reason_id)->value('system_key'));
    }

    public function test_unknown_product_fails_visibly_and_can_be_retried(): void
    {
        $payload = $this->payload(['line_items' => [['product_id' => 999, 'variation_id' => 0, 'sku' => 'NEW-1', 'name' => 'New thing', 'quantity' => 1, 'subtotal' => '100', 'total' => '100']]]);
        $this->send($payload)->assertOk();

        $inbox = DB::table('integration_inbox')->first();
        $this->assertSame('failed', $inbox->status);
        $this->assertStringContainsString('Unknown product', $inbox->error);

        Product::factory()->withVariant(100, ['sku' => 'NEW-1'])->create();
        $this->actingAs($this->owner())->post("/settings/integrations/inbox/{$inbox->id}/retry")->assertSessionHas('success');
        $this->assertSame(1, Order::count());
    }

    public function test_missing_secret_refuses_everything(): void
    {
        config(['store.woocommerce.webhook_secret' => '']);
        $this->send($this->payload())->assertStatus(503);
    }
}
