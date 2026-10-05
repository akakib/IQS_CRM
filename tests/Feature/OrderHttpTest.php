<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();

        $this->agent = User::factory()->create(['name' => 'Mahim']);
        $this->agent->roles()->attach($this->role(['orders.view' => 'own', 'orders.create', 'orders.edit', 'orders.take', 'products.view', 'customers.view'], [], 'Moderator')->id);
        app(\App\Services\PermissionService::class)->bump();
        Product::factory()->withVariant(600, ['sku' => 'DATE-500', 'weight_g' => 550])->create(['name' => 'Dates']);
        $this->variant = ProductVariant::firstWhere('sku', 'DATE-500');
    }

    private function webOrder(string $phone = '01712345678'): Order
    {
        return app(OrderService::class)->create([
            'channel' => 'web', 'phone' => $phone, 'name' => 'Web Buyer', 'address_line' => 'Road 1',
            'zone_id' => DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], null, 'webhook');
    }

    public function test_quick_order_form_creates_an_owned_order(): void
    {
        $this->actingAs($this->agent)->get('/orders/create')->assertOk()->assertSee('Quick order');

        $this->post('/orders', [
            'channel' => 'whatsapp', 'phone' => '01812345678', 'name' => 'Rahim', 'address_line' => 'House 9',
            'zone_id' => DB::table('delivery_zones')->where('system_key', 'outside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertRedirect();

        $order = Order::first();
        $this->assertSame($this->agent->id, $order->moderator_id);
        $this->assertSame('whatsapp', $order->channel);
        $this->assertSame('130.00', $order->delivery_charge);
    }

    public function test_new_website_order_notifies_moderators_and_is_given_by_take_next(): void
    {
        DB::table('notification_rules')->insert([
            'type_id' => DB::table('notification_types')->where('system_key', 'new_order')->value('id'),
            'target' => 'role', 'role_id' => DB::table('user_roles')->where('user_id', $this->agent->id)->value('role_id'),
            'channel_in_app' => true, 'channel_telegram' => false, 'channel_sms' => false, 'is_active' => true,
        ]);
        $order = $this->webOrder();

        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->agent->id, 'subject_type' => 'order', 'subject_id' => $order->id]);

        $this->actingAs($this->agent)->get('/orders?tab=take')->assertOk()->assertSee($order->order_no);
        $this->get("/orders/{$order->id}")->assertOk()->assertDontSee('Assign to me');
        $this->post('/desk/next')->assertSessionHas('success');
        $this->assertSame($this->agent->id, $order->fresh()->moderator_id);
        $this->get('/orders?tab=mine')->assertSee($order->order_no);
    }

    public function test_owner_moves_status_from_the_order_page_with_reason(): void
    {
        $order = $this->webOrder();
        app(\App\Services\Orders\DeskService::class)->assign($order->id, $this->agent->id, 'claimed');
        app(\App\Services\Orders\OrderStateMachine::class)->transition($order, 'record_verified', null, 'rule');
        $order->refresh();

        $this->actingAs($this->agent);
        $this->get("/orders/{$order->id}")->assertSee('Confirmed')->assertSee('No answer');

        $reason = DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'fake_order')->value('id');
        $this->post("/orders/{$order->id}/transition", ['to' => 'cancelled', 'reason_id' => $reason, 'lock_version' => $order->lock_version])
            ->assertSessionHas('success');
        $this->assertSame('cancelled', OrderStatus::map()[$order->fresh()->status_id]['key']);
    }

    public function test_agent_cannot_open_or_act_on_another_agents_order(): void
    {
        $other = User::factory()->create();
        $other->roles()->attach(DB::table('user_roles')->where('user_id', $this->agent->id)->value('role_id'));
        app(\App\Services\PermissionService::class)->bump();
        $order = $this->webOrder();
        app(\App\Services\Orders\DeskService::class)->assign($order->id, $other->id, 'claimed');

        $this->actingAs($this->agent)->get("/orders/{$order->id}")->assertForbidden();
        $this->post("/orders/{$order->id}/transition", ['to' => 'hold', 'lock_version' => 0])->assertForbidden();
        $this->get('/orders')->assertDontSee($order->order_no);
    }

    public function test_notes_timeline_and_list_query_budget(): void
    {
        $order = $this->webOrder();
        $this->actingAs($this->owner());
        $this->post("/orders/{$order->id}/notes", ['type' => 'call', 'body' => 'Called, will confirm tonight'])->assertSessionHas('success');
        $this->get("/orders/{$order->id}")->assertSee('Called, will confirm tonight');

        foreach (range(1, 30) as $i) {
            $this->webOrder('0171'.str_pad((string) $i, 7, '0', STR_PAD_LEFT));
        }
        DB::enableQueryLog();
        $this->get('/orders?per_page=25')->assertOk();
        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()));
    }

    public function test_search_by_order_number_and_phone(): void
    {
        $a = $this->webOrder('01712345678');
        $b = $this->webOrder('01812345678');
        $this->actingAs($this->owner());

        $this->get('/orders?q='.$a->order_no)->assertSee($a->order_no)->assertDontSee($b->order_no);
        $this->get('/orders?q=01812')->assertSee($b->order_no)->assertDontSee($a->order_no);
    }
}
