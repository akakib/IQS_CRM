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
use Illuminate\Support\Facades\Http;
use App\Models\WebsiteAccount;
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

    /** Same order, later webhook: status and (maybe) payment changed. */
    private function update(array $override = [])
    {
        return $this->send($this->payload($override + ['status' => 'processing']), self::SECRET, 'order.updated');
    }

    private function key(Order $o): string
    {
        return OrderStatus::map()[$o->fresh()->status_id]['key'];
    }

    public function test_website_status_drives_intake_and_repeats_change_nothing(): void
    {
        (new \Database\Seeders\ConfirmationSeeder)->run();
        $gateway = ['payment_method' => 'bkash', 'payment_method_title' => 'bKash'];

        // 1. A gateway order the customer never paid: not an order (yet). The same body again: duplicate.
        $this->send($this->payload($gateway + ['status' => 'pending']))->assertOk();
        $this->assertNull(Order::firstWhere('external_ref', '5001'));
        $this->assertSame('ignored', DB::table('integration_inbox')->value('status'));
        $this->send($this->payload($gateway + ['status' => 'pending']))->assertJson(['status' => 'duplicate']);

        // 2. Paid: "order.updated" says processing with a paid date (the created webhook was ignored: order matters, not topic).
        $this->update($gateway + ['transaction_id' => 'BK1', 'date_paid_gmt' => '2026-10-06T10:00:00'])->assertOk();
        $order = Order::firstWhere('external_ref', '5001');
        $this->assertNotNull($order);
        $this->assertDatabaseHas('order_payments', ['order_id' => $order->id, 'transaction_id' => 'BK1', 'status' => 'verified']);
        $this->assertSame('processing', $order->external_status);
        $this->assertSame(1, DB::table('order_payments')->where('order_id', $order->id)->count());

        // 3. Woo sends the same paid order twice more (retries, an admin note): nothing is added twice.
        $this->update($gateway + ['transaction_id' => 'BK1', 'date_paid_gmt' => '2026-10-06T10:00:00', 'customer_note' => 'x'])->assertOk();
        $this->update($gateway + ['transaction_id' => 'BK1', 'date_paid_gmt' => '2026-10-06T10:00:00', 'customer_note' => 'y'])->assertOk();
        $this->assertSame(1, DB::table('order_payments')->where('order_id', $order->id)->count());

        // 4. Edited on the website after import: IQS keeps its order, a note says so.
        $this->update($gateway + ['transaction_id' => 'BK1', 'date_paid_gmt' => '2026-10-06T10:00:00', 'total' => '1500.00'])->assertOk();
        $this->assertSame('1090.00', $order->fresh()->grand_total);
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'system']);
        $this->assertStringContainsString('Edited on the website', DB::table('order_notes')->where('order_id', $order->id)->orderByDesc('id')->value('body'));
    }

    public function test_paid_later_releases_the_advance_hold_and_manual_bkash_is_never_auto_verified(): void
    {
        (new \Database\Seeders\ConfirmationSeeder)->run();

        // A COD-looking order from a new customer (phone ends in 9): advance hold.
        $this->send($this->payload(['billing' => ['phone' => '+8801712345679'], 'payment_method' => 'bkash', 'payment_method_title' => 'bKash', 'status' => 'on-hold']))->assertOk();
        $order = Order::firstWhere('external_ref', '5001');
        $this->assertSame('hold', $this->key($order));

        // The customer pays at the gateway: verified, the advance is in, the order goes to Call by itself.
        $this->update(['billing' => ['phone' => '+8801712345679'], 'payment_method' => 'bkash', 'payment_method_title' => 'bKash', 'transaction_id' => 'LATE1', 'date_paid' => '2026-10-06T11:00:00'])->assertOk();
        $this->assertSame('0.00', $order->fresh()->cod_amount);
        $this->assertSame('record_verified', $this->key($order));

        // Manual bKash (send money): the website admin marks it processing, which gives it a paid date in Woo.
        // Still not verified here: it is checked on the Payments page.
        $manual = ['id' => 5002, 'number' => '5002', 'billing' => ['phone' => '+8801712345671'], 'payment_method' => 'manual_bkash', 'payment_method_title' => 'bKash (send money)', 'meta_data' => [['key' => '_trx_id', 'value' => 'MAN9']]];
        $this->send($this->payload($manual + ['status' => 'on-hold']))->assertOk();
        $m = Order::firstWhere('external_ref', '5002');
        $this->assertDatabaseHas('order_payments', ['order_id' => $m->id, 'transaction_id' => 'MAN9', 'status' => 'pending_verification']);
        $this->update($manual + ['date_paid' => '2026-10-06T12:00:00'])->assertOk();
        $this->assertSame('pending_verification', DB::table('order_payments')->where('order_id', $m->id)->value('status'));
        $this->assertSame(1, DB::table('order_payments')->where('order_id', $m->id)->count());

        // Cash on delivery marked "completed" in Woo (which sets a paid date): no payment, ever.
        $this->send($this->payload(['id' => 5003, 'number' => '5003', 'billing' => ['phone' => '+8801712345672']]))->assertOk();
        $this->update(['id' => 5003, 'number' => '5003', 'billing' => ['phone' => '+8801712345672'], 'status' => 'completed', 'date_paid' => '2026-10-07T12:00:00'])->assertOk();
        $this->assertSame(0, DB::table('order_payments')->where('order_id', Order::firstWhere('external_ref', '5003')->id)->count());
    }

    public function test_cancelled_on_the_website_cancels_only_an_untouched_order_otherwise_tells_the_moderator(): void
    {
        (new \Database\Seeders\ConfirmationSeeder)->run();
        Artisan::call('notifications:sync');

        // Untouched (nobody called, nothing booked): cancelled here too, with the website reason.
        $this->send($this->payload())->assertOk();
        $a = Order::firstWhere('external_ref', '5001');
        $this->update(['status' => 'cancelled'])->assertOk();
        $this->assertSame('cancelled', $this->key($a));
        $this->assertSame('website_cancelled', DB::table('status_reasons')->where('id', DB::table('order_events')->where('order_id', $a->id)->orderByDesc('id')->value('reason_id'))->value('system_key'));
        // The same cancel again: nothing more happens.
        $this->update(['status' => 'cancelled', 'customer_note' => 'again'])->assertOk();
        $this->assertSame(1, DB::table('order_events')->where('order_id', $a->id)->whereNotNull('reason_id')->count());

        // Already called: not cancelled by the website, the moderator is told.
        $this->send($this->payload(['id' => 5002, 'number' => '5002', 'billing' => ['phone' => '+8801712345671']]))->assertOk();
        $b = Order::firstWhere('external_ref', '5002');
        $mod = \App\Models\User::factory()->create();
        $b->forceFill(['moderator_id' => $mod->id])->save();
        app(\App\Services\Orders\OrderService::class)->note($b, 'call', 'Called: confirmed', $mod);
        $this->update(['id' => 5002, 'number' => '5002', 'billing' => ['phone' => '+8801712345671'], 'status' => 'cancelled'])->assertOk();
        $this->assertSame('record_verified', $this->key($b));
        $this->assertDatabaseHas('app_notifications', ['title' => "Cancelled on the website: {$b->order_no}"]);
    }

    public function test_website_paid_amount_that_differs_from_the_order_total_is_flagged(): void
    {
        (new \Database\Seeders\ConfirmationSeeder)->run();
        $this->send($this->payload(['payment_method' => 'bkash', 'payment_method_title' => 'bKash', 'transaction_id' => 'BKX', 'date_paid' => '2026-10-06T10:00:00', 'total' => '1000.00']))->assertOk();
        $order = Order::firstWhere('external_ref', '5001');
        $this->assertDatabaseHas('order_payments', ['order_id' => $order->id, 'amount' => 1000, 'status' => 'verified']);
        $this->assertStringContainsString('Website paid ৳1,000.00 but the order total here is ৳1,090.00', DB::table('order_notes')->where('order_id', $order->id)->where('note_type', 'system')->orderBy('id')->get()->pluck('body')->join(' '));
        $this->assertSame('90.00', $order->fresh()->cod_amount);
    }

    public function test_the_sweep_brings_orders_a_webhook_missed_and_changes_once(): void
    {
        WebsiteAccount::create(['name' => 'Shop', 'url' => 'https://shop.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs']);
        $missed = $this->payload(['id' => 7001, 'number' => '7001', 'billing' => ['phone' => '+8801712340001']]);
        $already = $this->payload(['id' => 7002, 'number' => '7002', 'billing' => ['phone' => '+8801712340002']]);
        $this->send($already)->assertOk(); // this one came by webhook
        Http::fake(['shop.test/wp-json/wc/v3/orders*' => Http::sequence()
            ->push([$missed, $already])
            ->push([$missed, $already])
            ->push([$missed, ['status' => 'cancelled'] + $already]),
        ]);
        $sweep = app(\App\Services\Orders\WooOrderSweep::class);

        // 1. The missed one comes in; the one already here with nothing new is left alone.
        $this->assertSame(1, $sweep->run());
        $this->assertNotNull(Order::firstWhere('external_ref', '7001'));
        $this->assertSame(1, Order::where('external_ref', '7002')->count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'modified_after='));

        // 2. Run again: nothing twice.
        $this->assertSame(0, $sweep->run());
        $this->assertSame(1, Order::where('external_ref', '7001')->count());

        // 3. Cancelled on the website and that webhook lost: the sweep brings the change (nobody called: cancelled here too).
        $this->assertSame(1, $sweep->run());
        $this->assertSame('cancelled', OrderStatus::map()[Order::firstWhere('external_ref', '7002')->status_id]['key']);
    }

    public function test_website_payment_comes_in_with_the_order(): void
    {
        (new \Database\Seeders\ConfirmationSeeder)->run();

        // Paid through the bKash gateway: verified at once, the order is fully prepaid and goes to Call.
        $this->send($this->payload(['payment_method' => 'bkash', 'payment_method_title' => 'bKash', 'transaction_id' => 'BK123', 'date_paid' => '2026-10-06T10:00:00']))->assertOk();
        $paid = Order::firstWhere('external_ref', '5001');
        $this->assertDatabaseHas('order_payments', ['order_id' => $paid->id, 'transaction_id' => 'BK123', 'status' => 'verified']);
        $this->assertSame('0.00', $paid->fresh()->cod_amount);
        $this->assertSame('record_verified', OrderStatus::map()[$paid->fresh()->status_id]['key']);

        // Only a TrxID typed by the customer (manual bKash): to check on the Payments page.
        $this->send($this->payload(['id' => 5002, 'number' => '5002', 'status' => 'on-hold', 'payment_method' => 'manual_bkash', 'payment_method_title' => 'bKash (send money)',
            'billing' => ['phone' => '+8801712345671'], 'meta_data' => [['key' => '_bkash_trx_id', 'value' => 'MAN55']]]))->assertOk();
        $manual = Order::firstWhere('external_ref', '5002');
        $this->assertDatabaseHas('order_payments', ['order_id' => $manual->id, 'transaction_id' => 'MAN55', 'status' => 'pending_verification']);

        // Cash on delivery: no payment.
        $this->send($this->payload(['id' => 5003, 'number' => '5003', 'billing' => ['phone' => '+8801712345672']]))->assertOk();
        $this->assertSame(0, DB::table('order_payments')->where('order_id', Order::firstWhere('external_ref', '5003')->id)->count());
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
        $this->assertDatabaseHas('integration_inbox', ['status' => 'processed', 'order_id' => $order->id]);
    }

    public function test_bad_signature_is_rejected_and_duplicates_are_ignored(): void
    {
        $owner = $this->owner();
        // Website connection shows the state: nothing, refused (wrong secret), connected (Woo's ping on save).
        $this->actingAs($owner)->get('/settings/integrations')->assertSee('Not connected yet');
        $this->send($this->payload(), 'wrong')->assertStatus(401);
        $this->assertSame(0, Order::count());
        $this->get('/settings/integrations')->assertSee('the secret did not match');
        $this->travel(1)->minute();
        $this->post('/webhooks/woocommerce', ['webhook_id' => 7])->assertJson(['status' => 'pong']);
        $this->get('/settings/integrations')->assertSee('Connected: the website reaches IQS');

        $this->send($this->payload())->assertOk();
        $this->send($this->payload())->assertJson(['status' => 'duplicate']);
        $this->send($this->payload(), self::SECRET, 'order.updated')->assertJson(['status' => 'duplicate']); // same body: nothing new
        $this->send($this->payload(), self::SECRET, 'order.deleted')->assertJson(['status' => 'ignored']);
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
