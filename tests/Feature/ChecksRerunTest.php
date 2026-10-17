<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ConfirmationSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChecksRerunTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_checks_again_answers_in_place_and_never_moves_a_confirmed_order_back(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new ConfirmationSeeder)->run();
        OrderStatus::forget();
        $boss = User::factory()->create();
        $boss->roles()->attach($this->role(['orders.view' => 'all', 'orders.edit', 'orders.approve'], [], 'Manager')->id);
        app(\App\Services\PermissionService::class)->bump();
        Product::factory()->withVariant(600, ['sku' => 'D-1'])->create(['name' => 'Dates']);
        $order = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '01712345678', 'name' => 'Rahim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::firstWhere('sku', 'D-1')->id, 'qty' => 1]],
        ], null, 'webhook');
        DB::table('orders')->where('id', $order->id)->update(['status_id' => OrderStatus::idFor('confirmed')]);

        $this->actingAs($boss)->get('/orders/'.$order->id)->assertOk()->assertSee('id="order-checks"', false)->assertSee('id="order-timeline"', false)->assertSee('Run checks again');
        $this->postJson('/orders/'.$order->id.'/verify')->assertOk()->assertJson(['message' => 'Checks run again. Status unchanged.']);

        $this->assertSame('confirmed', OrderStatus::map()[$order->fresh()->status_id]['key']);
        $note = DB::table('order_notes')->where('order_id', $order->id)->where('note_type', 'verification')->orderByDesc('id')->value('body');
        $this->assertStringEndsWith('(order already Confirmed, status unchanged).', $note);
        // Pressed again within a minute: waits, so Steadfast is not asked over and over.
        $this->postJson('/orders/'.$order->id.'/verify')->assertStatus(429);
        \Illuminate\Support\Facades\Cache::forget('verify-again:'.$order->id);
        // The old form post still works (no JavaScript).
        $this->from('/orders/'.$order->id)->post('/orders/'.$order->id.'/verify')->assertRedirect('/orders/'.$order->id)->assertSessionHas('success', 'Checks run again. Status unchanged.');
    }

    public function test_steadfast_busy_says_so_on_the_desk_and_the_person_on_the_order_can_check_again(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new ConfirmationSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(600, ['sku' => 'D-1'])->create(['name' => 'Dates']);
        $order = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '01712345678', 'name' => 'Rahim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::firstWhere('sku', 'D-1')->id, 'qty' => 1]],
        ], null, 'webhook');
        // The checks ran with only our own history: Steadfast did not answer.
        DB::table('verification_runs')->insert(['order_id' => $order->id, 'outcome' => 'manual_review', 'ran_by' => 'system', 'created_at' => now(),
            'inputs_snapshot' => json_encode(['providers' => [1 => ['key' => 'internal', 'success_rate' => null, 'total_parcels' => 0, 'detail' => []]]])]);
        $agent = User::factory()->create();
        $agent->roles()->attach($this->role(['orders.view' => 'own', 'orders.edit'], [], 'Agent')->id);
        app(\App\Services\PermissionService::class)->bump();
        DB::table('orders')->where('id', $order->id)->update(['moderator_id' => $agent->id]);

        $this->actingAs($agent)->get(route('desk.index', ['order' => $order->id]))->assertOk()->assertSee('Steadfast: not checked (busy)')->assertSee('Check again');
        $this->post('/orders/'.$order->id.'/verify')->assertSessionHas('success');
        $this->get(route('desk.index', ['order' => $order->id]))->assertDontSee('not checked (busy)'); // the fake Steadfast answered
        // Someone else's order: no.
        $other = User::factory()->create();
        $other->roles()->attach(DB::table('roles')->where('name', 'Agent')->value('id'));
        app(\App\Services\PermissionService::class)->bump();
        \Illuminate\Support\Facades\Cache::forget('verify-again:'.$order->id);
        $this->actingAs($other)->post('/orders/'.$order->id.'/verify')->assertForbidden();
    }
}
