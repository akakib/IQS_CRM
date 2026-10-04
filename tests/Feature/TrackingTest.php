<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use App\Services\Tracking\TrackingSender;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ConfirmationSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new ConfirmationSeeder)->run();
        DB::table('verification_rules')->delete(); // keep orders New so the test drives every step
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        Product::factory()->withVariant(700, ['sku' => 'PIS'])->create(['name' => 'Pistachio']);
    }

    private function webOrder(): Order
    {
        return app(OrderService::class)->create([
            'channel' => 'web', 'external_ref' => '8801', 'phone' => '01712345678', 'name' => 'Rafi Ahmed', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 2]], 'fbp' => 'fb.1.999',
        ], null, 'webhook');
    }

    private function sendQueued(): void
    {
        foreach (DB::table('tracking_event_logs')->where('status', 'queued')->pluck('id') as $id) {
            app(TrackingSender::class)->send($id);
        }
    }

    public function test_purchase_fires_once_on_confirmed_with_the_pixel_event_id(): void
    {
        $order = $this->webOrder();
        $sm = app(OrderStateMachine::class);
        $sm->transition($order, 'record_verified', null, 'rule');
        $this->assertSame(0, DB::table('tracking_event_logs')->count());

        $sm->transition($order, 'confirmed', null, 'rule');
        $this->sendQueued();

        $log = DB::table('tracking_event_logs')->first();
        $this->assertSame('wc_purchase_8801', $log->event_id);
        $this->assertSame('sent', $log->status);
        $this->assertEquals(1400, (float) $log->value);
        $payload = json_decode($log->request_payload, true)['data'][0];
        $this->assertSame(hash('sha256', '8801712345678'), $payload['user_data']['ph'][0]);
        $this->assertSame('fb.1.999', $payload['user_data']['fbp']);
        $this->assertSame('PIS', $payload['custom_data']['contents'][0]['id']);
        $this->assertTrue(json_decode($log->response, true)['fake']); // never live outside production

        // Hold and re-confirm: still one Purchase.
        $sm->transition($order, 'hold', $this->owner(), 'user', DB::table('status_reasons')->where('system_key', 'customer_wait')->value('id'));
        $sm->transition($order, 'confirmed', $this->owner(), 'user');
        $this->assertSame(1, DB::table('tracking_event_logs')->count());
    }

    public function test_timing_is_configurable_and_no_http_call_outside_production(): void
    {
        Http::fake();
        $this->actingAs($this->owner());
        $id = DB::table('tracking_event_settings')->value('id');
        $this->put("/settings/tracking/{$id}", ['fire_on' => 'order_created', 'value_basis' => 'product_subtotal', 'channels' => ['web'], 'is_active' => 1])
            ->assertSessionHas('success');

        $this->webOrder();
        $this->sendQueued();

        $this->assertSame(1, DB::table('tracking_event_logs')->where('status', 'sent')->count());
        Http::assertNothingSent();
        $this->get('/settings/tracking')->assertOk()->assertSee('wc_purchase_8801');
    }
}
