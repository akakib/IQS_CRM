<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_packed_and_delivered_side_by_side_in_kg_and_litres(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        \App\Models\OrderStatus::forget();
        $this->travelTo(now()->setTime(12, 0));
        Product::factory()->withVariant(500, ['sku' => 'DATE-500'])->create(['name' => 'Dates 500g']);
        Product::factory()->withVariant(300, ['sku' => 'OIL-1L'])->create(['name' => 'Oil 1 L']);
        $dates = ProductVariant::firstWhere('sku', 'DATE-500');
        $oil = ProductVariant::firstWhere('sku', 'OIL-1L');
        DB::table('product_variants')->where('id', $dates->id)->update(['size_qty' => 500, 'size_unit' => 'g']);
        DB::table('product_variants')->where('id', $oil->id)->update(['size_qty' => 1000, 'size_unit' => 'ml']);
        $owner = $this->owner();
        $sm = app(OrderStateMachine::class);
        $make = function (array $items) {
            static $n = 0;
            $n++;

            return app(OrderService::class)->create(['channel' => 'messenger', 'phone' => '0171200000'.$n, 'name' => 'C'.$n, 'address_line' => 'R',
                'items' => $items], null);
        };

        // Two confirmed today: 4 x 500 g dates and 2 x 1 L oil; the first also packed and delivered.
        $a = $make([['variant_id' => $dates->id, 'qty' => 3]]);
        $b = $make([['variant_id' => $oil->id, 'qty' => 2], ['variant_id' => $dates->id, 'qty' => 1]]);
        foreach ([$a, $b] as $o) {
            $sm->transition($o->fresh(), 'record_verified', null, 'rule');
            $sm->transition($o->fresh(), 'confirmed', $owner);
        }
        DB::table('orders')->where('id', $a->id)->update(['packed_at' => now()]);
        DB::table('order_events')->insert(['order_id' => $a->id, 'from_status_id' => null, 'to_status_id' => \App\Models\OrderStatus::idFor('delivered'), 'source' => 'webhook', 'created_at' => now()]);

        $s = app(\App\Services\Reports\SalesReport::class)->summary(today()->toDateString(), today()->toDateString());
        $this->assertSame([2, 2000.0, 2000.0], [$s['confirmed']['orders'], $s['confirmed']['grams'], $s['confirmed']['ml']]);
        $this->assertSame([1, 1500.0, 0.0], [$s['packed']['orders'], $s['packed']['grams'], $s['packed']['ml']]);
        $this->assertSame([1, 1500.0], [$s['delivered']['orders'], $s['delivered']['grams']]);

        // The page: the three side by side, the chosen step's items, by day; Print / PDF and Excel.
        $this->actingAs($owner)->get(route('sales-report.index'))->assertOk()
            ->assertSeeInOrder(['Confirmed', '2', 'Packed', '1', 'Delivered', '1'])->assertSee('2 kg · 2 L')->assertSee('Dates 500g')->assertSee('1.5 kg')
            ->assertSee('Confirmed by day')->assertSee('Where the orders came from')->assertSee('Messenger');

        // Where they came from: both new customers; then a third order by the first (delivered) customer is returning.
        $sales = app(\App\Services\Reports\SalesReport::class);
        $this->assertSame([2, 0], [(int) $sales->channels('confirmed', today()->toDateString(), today()->toDateString())[0]->orders, (int) $sales->channels('confirmed', today()->toDateString(), today()->toDateString())[0]->ret]);
        DB::table('orders')->where('id', $a->id)->update(['status_id' => \App\Models\OrderStatus::idFor('delivered')]);
        $c = app(OrderService::class)->create(['channel' => 'whatsapp', 'customer_id' => $a->customer_id, 'phone' => '01712000001', 'name' => 'C1', 'address_line' => 'R',
            'items' => [['variant_id' => $dates->id, 'qty' => 1]]], null);
        $sm->transition($c->fresh(), 'record_verified', null, 'rule');
        $sm->transition($c->fresh(), 'confirmed', $owner);
        $wa = $sales->channels('confirmed', today()->toDateString(), today()->toDateString())->firstWhere('channel', 'whatsapp');
        $this->assertSame([1, 1], [(int) $wa->orders, (int) $wa->ret]);

        // The popups: a channel's, an item's and a day's orders.
        $this->get(route('sales-report.orders', ['channel' => 'whatsapp']))->assertOk()->assertSee($c->order_no)->assertSee('Repeat customer');
        $this->get(route('sales-report.orders', ['item' => $oil->id]))->assertOk()->assertSee($b->order_no)->assertDontSee($a->order_no)->assertSee('Returned: 0');
        $this->get(route('sales-report.orders', ['day' => today()->toDateString()]))->assertOk()->assertSee('3 orders');
        $this->get(route('sales-report.orders'))->assertNotFound();

        // By category: the item with its sizes (kg apart from litres), and the item's orders across its sizes.
        $cat = $sales->categories($sales->products('confirmed', today()->toDateString(), today()->toDateString()))->firstWhere('name', 'No category');
        $dates = collect($cat['items'])->firstWhere('name', 'Dates 500g');
        $this->assertSame([2500.0, 0.0], [$dates['grams'], $dates['ml']]);
        $this->assertSame([2000.0, 2000.0], [$cat['grams'] - 500, $cat['ml']]);
        $this->get(route('sales-report.index'))->assertSee('Tap a category for its items by size.');
        $this->get(route('sales-report.orders', ['product' => $dates['product_id']]))->assertOk()->assertSee($a->order_no)->assertSee($c->order_no);

        // Cancelled after it was confirmed: still in today's Confirmed (it was confirmed today), told beside it and in the popup.
        DB::table('orders')->where('id', $b->id)->update(['status_id' => \App\Models\OrderStatus::idFor('cancelled')]);
        $this->assertSame([3, 1, 0], [$sales->summary(today()->toDateString(), today()->toDateString())['confirmed']['orders'], $sales->summary(today()->toDateString(), today()->toDateString())['confirmed']['cancelled'], $sales->summary(today()->toDateString(), today()->toDateString())['confirmed']['hold']]);
        $this->get(route('sales-report.index'))->assertSee('Since then: 1 cancelled');
        $this->get(route('sales-report.orders', ['item' => $oil->id]))->assertSee('Now cancelled');
        $this->get(route('sales-report.index', ['stage' => 'packed']))->assertOk()->assertSee('1.5 kg')->assertDontSee('Oil 1 L');
        $this->get(route('sales-report.print', ['stage' => 'confirmed']))->assertOk()->assertSee('Sales: Confirmed')->assertSee('Oil 1 L');
        $this->get(route('sales-report.excel'))->assertOk()->assertDownload();

        $staff = User::factory()->create();
        $this->actingAs($staff)->get(route('sales-report.index'))->assertForbidden();
    }
}
