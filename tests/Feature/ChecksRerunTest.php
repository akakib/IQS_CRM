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
        // The old form post still works (no JavaScript).
        $this->from('/orders/'.$order->id)->post('/orders/'.$order->id.'/verify')->assertRedirect('/orders/'.$order->id)->assertSessionHas('success', 'Checks run again. Status unchanged.');
    }
}
