<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Courier\BookingService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourierWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'sf-token';

    private Order $order;

    private string $cn;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new RoleSeeder)->run();
        (new NotificationSeeder)->run();
        OrderStatus::forget();
        config(['courier.steadfast.webhook_token' => self::TOKEN]);

        Product::factory()->withVariant(900)->create();
        $owner = $this->owner();
        $order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]],
        ], $owner);
        $sm = app(OrderStateMachine::class);
        $sm->transition($order, 'record_verified', null, 'rule');
        $sm->transition($order, 'confirmed', $owner);
        app(BookingService::class)->book([$order->id], $owner);
        $this->order = $order->fresh();
        $this->cn = DB::table('shipments')->where('order_id', $order->id)->value('consignment_id');
    }

    private function hook(array $payload, string $token = self::TOKEN)
    {
        return $this->withToken($token)->postJson('/webhooks/steadfast', $payload);
    }

    private function key(): string
    {
        return OrderStatus::map()[$this->order->fresh()->status_id]['key'];
    }

    public function test_delivered_webhook_finishes_the_order_and_counts_for_the_customer(): void
    {
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $this->cn, 'invoice' => $this->order->order_no,
            'status' => 'delivered', 'cod_amount' => 1000, 'delivery_charge' => 60, 'tracking_message' => 'Delivered to customer'])
            ->assertOk()->assertJson(['status' => 'received']);

        $this->assertSame('delivered', $this->key());
        $events = DB::table('order_events')->where('order_id', $this->order->id)->where('source', 'webhook')->pluck('to_status_id')
            ->map(fn ($id) => OrderStatus::map()[$id]['key'])->all();
        $this->assertSame(['in_transit', 'delivered'], $events); // handover scan was skipped: noted, not stuck
        $this->assertSame(1, Customer::first()->delivered_count);
        $shipment = DB::table('shipments')->where('order_id', $this->order->id)->first();
        $this->assertEquals(60, (float) $shipment->delivery_charge);
        $this->assertNotNull($shipment->final_at);
        $this->assertNotNull(DB::table('courier_events')->value('processed_at'));
    }

    public function test_tracking_update_goes_to_the_timeline(): void
    {
        $this->hook(['notification_type' => 'tracking_update', 'consignment_id' => $this->cn, 'tracking_message' => 'Rider picked up the parcel']);

        $this->assertDatabaseHas('order_notes', ['order_id' => $this->order->id, 'note_type' => 'courier', 'body' => 'Courier: Rider picked up the parcel']);
        $this->assertSame('ready_for_packaging', $this->key());
    }

    public function test_hold_opens_a_delivery_issue_for_the_moderator(): void
    {
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => $this->cn, 'status' => 'hold']);

        $issue = DB::table('delivery_issues')->where('order_id', $this->order->id)->first();
        $this->assertSame('hold', $issue->issue_type);
        $this->assertSame($this->order->moderator_id, $issue->assigned_to);
        $this->assertNotNull($issue->sla_due_at);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->order->moderator_id, 'subject_type' => 'order', 'priority' => 'urgent']);
        $this->assertSame('ready_for_packaging', $this->key());
    }

    public function test_cancelled_is_a_return_with_a_reason_to_set(): void
    {
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => $this->cn, 'status' => 'cancelled']);

        $this->assertSame('returned', $this->key());
        $this->assertSame(1, Customer::first()->returned_count);
        $this->assertDatabaseHas('delivery_issues', ['order_id' => $this->order->id, 'issue_type' => 'cancel']);
    }

    public function test_bad_token_and_duplicates(): void
    {
        $payload = ['notification_type' => 'delivery_status', 'consignment_id' => $this->cn, 'status' => 'delivered'];
        $this->hook($payload, 'wrong')->assertStatus(401);
        $this->hook($payload)->assertOk();
        $this->hook($payload)->assertJson(['status' => 'duplicate']);
        $this->assertSame(1, DB::table('courier_events')->count());
    }

    public function test_resync_pulls_status_from_the_fake_courier(): void
    {
        $this->travel(3)->hours(); // fake journey reaches delivered
        Artisan::call('courier:resync');

        $this->assertSame('delivered', $this->key());
        $this->assertDatabaseHas('courier_events', ['notification_type' => 'resync']);
    }
}
