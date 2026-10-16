<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Support\Phone;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A customer's journey: found by any form of the phone or by name; sales count delivered money only. */
class CustomerJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_forms_all_normalise(): void
    {
        foreach (['01712345678', '+880 1712-345678', '8801712345678', '008801712345678', '1712345678', '০১৭১২৩৪৫৬৭৮', '+৮৮০১৭১২৩৪৫৬৭৮',
            '+880 01712345678', '+88 1712345678', '88 01712345678', '0088 01712345678', '+0 1712345678', '(+88) 017 1234 5678'] as $in) {
            $this->assertSame('01712345678', Phone::normalize($in), $in);
        }
        foreach (['017123456788', '0171234567', '8812345678', '02-9876543', '88017123456'] as $in) {
            $this->assertNull(Phone::normalize($in), $in); // a digit too many or too few is never guessed at
        }
        $this->assertSame('01881234567', Phone::normalize('01881234567')); // a real 018 number keeps its 88
    }

    public function test_journey_shows_orders_sales_and_losses(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(700)->create(['name' => 'Ajwa Dates']);
        $owner = $this->owner();
        $make = fn () => app(OrderService::class)->create(['channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim Uddin', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]]], $owner);
        $first = $make();
        $second = $make();
        DB::table('orders')->where('id', $first->id)->update(['status_id' => OrderStatus::idFor('delivered'), 'created_at' => now()->subDays(20)]);
        DB::table('orders')->where('id', $second->id)->update(['status_id' => OrderStatus::idFor('cancelled')]);
        $customer = $first->customer_id;

        // The old journey search lands on the one search; any form of the phone, the name, or nothing.
        $this->actingAs($owner)->get('/journey?q=Karim')->assertRedirect(route('orders.search', ['q' => 'Karim']));
        foreach (['+880 1712-345678', '০১৭১২৩৪৫৬৭৮', '008801712345678', '1712345678'] as $q) {
            $res = $this->get('/order-search?q='.urlencode($q))->assertOk()->assertSee('Open customer')->assertSee(route('customers.show', $customer), false);
            $this->assertSame([$customer], $res->viewData('customers')->pluck('id')->all(), $q);
        }
        $this->get('/order-search?q=Karim')->assertSee(route('customers.show', $customer), false);
        $this->get('/order-search?q=Nobody')->assertSee('No order found for');

        $total = number_format((float) $first->fresh()->grand_total);
        $this->get(route('journey.show', $customer))->assertOk()
            ->assertSee($first->order_no)->assertSee($second->order_no)->assertSee('Ajwa Dates')
            ->assertSee('Delivered sales')->assertSee('৳'.$total)
            ->assertSee('1 cancelled', false)->assertSee('20 days later');

        // Order search links to the journey; a role without customers.view cannot open it.
        $this->get('/order-search?q='.$first->order_no)->assertSee(route('journey.show', $customer), false);
        $this->get('/order-search?q='.urlencode('১০'.substr($first->order_no, 4)))->assertOk();
        $packer = User::factory()->create();
        $packer->roles()->attach($this->role(['packaging.view'], [], 'Packer')->id);
        $this->actingAs($packer)->get(route('journey.show', $customer))->assertForbidden();
        $this->get('/order-search?q='.$first->order_no)->assertDontSee(route('journey.show', $customer), false)->assertDontSee('Open customer');
    }
}
