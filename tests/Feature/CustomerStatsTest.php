<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** One count of a customer's orders, delivered and returned: IQS orders and imported ones, the same on every screen. */
class CustomerStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_page_and_journey_count_the_same(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(700)->create();
        $owner = $this->owner();
        $order = app(OrderService::class)->create(['channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]]], $owner);
        $customer = Customer::find($order->customer_id);
        $this->assertSame([1, 0, 0], [$customer->orders_count, $customer->delivered_count, $customer->returned_count]);

        // Two imported parcels for the same number: one delivered, one returned.
        $csv = "Name,CN,Phone,Address,Products,Amount,Date,Status\nKarim,DM1,01712345678,Feni,Dates,900,2026-07-02,Delivered\nKarim,DM2,01712345678,Feni,Dates,500,2026-07-09,Returned\n";
        $this->actingAs($owner)->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('old.csv', $csv)]);
        $customer->refresh();
        $this->assertSame([3, 1, 1], [$customer->orders_count, $customer->delivered_count, $customer->returned_count]);

        // A status changed in IQS counts too.
        DB::table('orders')->where('id', $order->id)->update(['status_id' => OrderStatus::idFor('in_transit') ?? OrderStatus::idFor('confirmed')]);
        app(OrderStateMachine::class)->transition($order->fresh(), 'delivered', null, 'webhook'); // the courier says delivered
        $customer->refresh();
        $this->assertSame([3, 2, 1], [$customer->orders_count, $customer->delivered_count, $customer->returned_count]);

        // The customer page shows these numbers, and so does the journey.
        $page = $this->get(route('customers.show', $customer))->assertOk();
        $this->assertSame(2, $page->viewData('customer')->delivered_count);
        $journey = $this->get(route('journey.show', $customer))->assertOk()->viewData('stats');
        $this->assertSame([3, 2, 1], [$journey['orders'], $journey['delivered'], $journey['returned'][0]]);

        // Undo the import: back to the IQS order only.
        $import = (int) DB::table('customer_imports')->max('id');
        $this->post(route('customers.history.undo', $import));
        $customer->refresh();
        $this->assertSame([1, 1, 0], [$customer->orders_count, $customer->delivered_count, $customer->returned_count]);
    }
}
