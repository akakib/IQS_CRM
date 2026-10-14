<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Courier\BookingService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every staff member finds an order by number, phone, CN or name; nothing shows before a search. */
class OrderSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_staff_member_finds_an_order_four_ways(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(700)->create(['name' => 'Ajwa Dates']);
        $owner = $this->owner();
        $order = app(OrderService::class)->create(['channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim Uddin', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]]], $owner);
        $sm = app(OrderStateMachine::class);
        $sm->transition($order, 'record_verified', null, 'rule');
        $sm->transition($order->fresh(), 'confirmed', $owner);
        if (! DB::table('shipments')->where('order_id', $order->id)->exists()) {
            app(BookingService::class)->book([$order->id], $owner);
        }
        $cn = DB::table('shipments')->where('order_id', $order->id)->value('consignment_id');

        // A packer with no order permissions at all.
        $packer = User::factory()->create(['name' => 'Packer']);
        $packer->roles()->attach($this->role(['packaging.view'], [], 'Packer')->id);
        $this->actingAs($packer)->get('/order-search')->assertOk()->assertSee('Find any order')->assertDontSee($order->order_no);
        foreach ([$order->order_no, substr($order->order_no, 2), '01712345678', $cn, 'Karim', 'Uddin'] as $q) {
            $this->get('/order-search?q='.urlencode($q))->assertOk()->assertSee($order->order_no)->assertSee('Ajwa Dates');
        }
        // Search in one field only: a name is not found under CN or Phone.
        $this->get('/order-search?by=name&q=Karim')->assertSee($order->order_no);
        $this->get('/order-search?by=cn&q=Karim')->assertSee('No order found for');
        $this->get('/order-search?by=phone&q=01712345678')->assertSee($order->order_no);
        $this->get('/order-search?by=cn&q='.$cn)->assertSee($order->order_no);
        $this->get('/order-search?by=phone&q='.$cn)->assertSee('No order found for');
        $this->get('/order-search?q=ka')->assertSee('Type at least 3 characters');
        $this->get('/order-search?q=Nobody')->assertSee('No order found for');
        // They see it, but the order page is not a link for them.
        $this->get('/order-search?q='.$order->order_no)->assertDontSee(route('orders.show', $order), false);
        $this->actingAs($owner)->get('/order-search?q='.$order->order_no)->assertSee(route('orders.show', $order), false);
    }
}
