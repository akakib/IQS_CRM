<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBackLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_back_goes_to_the_page_the_customer_was_opened_from(): void
    {
        $this->actingAs($this->owner());
        $c = Customer::create(['name' => 'T5 TEST Back', 'primary_phone' => '01300009999']);

        // Opened from a search first...
        $this->from('/order-search?q=T5')->get(route('customers.show', $c))->assertSee('Back to results')->assertSee('/order-search?q=T5', false);
        // ...then from the customer list: Back now goes to that list, as it was sorted.
        $list = route('customers.index', ['sort' => 'delivered_count', 'dir' => 'desc']);
        $this->from($list)->get(route('customers.show', $c))->assertSee('Back to customers')->assertDontSee('/order-search?q=T5', false);
        // A reload or an action on the page keeps the list it came from.
        $this->from(route('customers.show', $c))->get(route('customers.show', $c))->assertSee('Back to customers');
    }
}
