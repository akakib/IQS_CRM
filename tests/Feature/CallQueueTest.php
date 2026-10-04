<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CallQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        $this->agent = User::factory()->create();
        $this->agent->roles()->attach($this->role(['orders.view' => 'own', 'orders.create', 'orders.edit'], [], 'Moderator')->id);
        app(\App\Services\PermissionService::class)->bump();
        Product::factory()->withVariant(500, ['sku' => 'X'])->create(['name' => 'Raisins']);
    }

    private function verifiedWebOrder(string $phone = '01712345678'): Order
    {
        $order = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => $phone, 'name' => 'Buyer', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]],
        ], null, 'webhook');
        app(OrderStateMachine::class)->transition($order, 'record_verified', null, 'rule');

        return $order->fresh();
    }

    public function test_take_next_then_confirm_from_the_queue_logs_the_call(): void
    {
        $order = $this->verifiedWebOrder();
        $this->actingAs($this->agent);

        $this->post('/orders/queue/next')->assertSessionHas('success');
        $this->get('/orders/queue')->assertOk()->assertSee($order->order_no)->assertSee('Raisins');

        $this->post("/orders/{$order->id}/call", ['outcome' => 'confirmed', 'note' => 'Wants delivery after 5pm'])->assertSessionHas('success');

        $this->assertSame('confirmed', OrderStatus::map()[$order->fresh()->status_id]['key']);
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'call', 'user_id' => $this->agent->id]);
    }

    public function test_web_order_cannot_be_confirmed_without_a_logged_call(): void
    {
        $order = $this->verifiedWebOrder();
        app(OrderService::class)->claim($order, $this->agent);
        $this->actingAs($this->agent);

        $this->post("/orders/{$order->id}/transition", ['to' => 'confirmed', 'lock_version' => $order->fresh()->lock_version])
            ->assertSessionHasErrors('status');

        $this->post("/orders/{$order->id}/notes", ['type' => 'call', 'body' => 'Spoke to customer']);
        $this->post("/orders/{$order->id}/transition", ['to' => 'confirmed', 'lock_version' => $order->fresh()->lock_version])
            ->assertSessionHas('success');
    }

    public function test_no_answer_stops_after_the_limit(): void
    {
        $order = $this->verifiedWebOrder();
        app(OrderService::class)->claim($order, $this->agent);
        $this->actingAs($this->agent);

        foreach (range(1, 3) as $i) {
            $this->post("/orders/{$order->id}/call", ['outcome' => 'no_answer'])->assertSessionHas('success');
        }
        $this->post("/orders/{$order->id}/call", ['outcome' => 'no_answer'])->assertSessionHasErrors('order');
        $this->assertSame(3, DB::table('order_events')->where('order_id', $order->id)->where('to_status_id', OrderStatus::idFor('no_answer'))->count());

        $reason = DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'unreachable')->value('id');
        $this->post("/orders/{$order->id}/call", ['outcome' => 'cancelled', 'reason_id' => $reason])->assertSessionHas('success');
    }

    public function test_cannot_log_calls_on_someone_elses_order(): void
    {
        $order = $this->verifiedWebOrder();
        app(OrderService::class)->claim($order, $this->owner());

        $this->actingAs($this->agent)->post("/orders/{$order->id}/call", ['outcome' => 'confirmed'])->assertForbidden();
    }
}
