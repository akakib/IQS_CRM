<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use App\Support\Barcode\Code128;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShippingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        Product::factory()->withVariant(800, ['sku' => 'WAL'])->create(['name' => 'Walnut']);
        $this->actingAs($this->owner());
    }

    private function confirmedOrder(string $phone = '01712345678'): Order
    {
        $order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => $phone, 'name' => 'Karim', 'address_line' => 'House 5', 'thana' => 'Banani', 'district' => 'Dhaka',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]],
        ], auth()->user());
        $sm = app(OrderStateMachine::class);
        $sm->transition($order, 'record_verified', null, 'rule');
        $sm->transition($order, 'confirmed', auth()->user());
        // Confirm books by itself; this page is for the ones whose booking failed.
        DB::table('orders')->where('id', $order->id)->update(['booking_state' => 'failed']);

        return $order->fresh();
    }

    public function test_code128_patterns_and_checksum(): void
    {
        foreach ((new \ReflectionClassConstant(Code128::class, 'PATTERNS'))->getValue() as $i => $p) {
            $this->assertSame($i === 106 ? 13 : 11, array_sum(str_split($p)), "pattern {$i}");
        }
        // "PJJ123C": (104 + 48·1 + 42·2 + 42·3 + 17·4 + 18·5 + 19·6 + 35·7) mod 103 = 879 mod 103 = 55.
        $values = Code128::values('PJJ123C');
        $this->assertSame(104, $values[0]);
        $this->assertSame(55, $values[count($values) - 2]);
        $this->assertStringContainsString('<svg', Code128::svg('IQ10001-1'));
    }

    public function test_bulk_booking_creates_shipment_label_and_moves_to_ready_for_packaging(): void
    {
        $a = $this->confirmedOrder('01712345678');
        $b = $this->confirmedOrder('01812345678');

        $this->get('/shipping')->assertOk()->assertSee($a->order_no)->assertSee('Test mode');
        $this->post('/shipping/book', ['ids' => [$a->id, $b->id]])
            // Stays on Courier booking; the labels open in their own tab from the banner.
            ->assertRedirect(route('shipping.index').'#booked')
            ->assertSessionHas('print_labels', route('shipping.labels', ['orders' => $a->order_no.','.$b->order_no]));
        $this->get('/shipping')->assertSee('Labels are ready to print.');

        foreach ([$a, $b] as $o) {
            $o->refresh();
            $this->assertSame('ready_for_packaging', OrderStatus::map()[$o->status_id]['key']);
            $shipment = DB::table('shipments')->find($o->active_shipment_id);
            $this->assertMatchesRegularExpression('/^\d{9}$/', $shipment->consignment_id);
            $this->assertDatabaseHas('shipment_labels', ['order_id' => $o->id, 'barcode' => $o->order_no.'-1', 'voided_at' => null]);
        }

        $this->get('/shipping/labels?orders='.$a->order_no)->assertOk()->assertSee($a->order_no.'-1')->assertSee('<svg', false);
        $this->assertNotNull(DB::table('shipment_labels')->where('order_id', $a->id)->value('printed_at'));
    }

    public function test_booking_twice_does_not_create_a_second_parcel(): void
    {
        $order = $this->confirmedOrder();
        $this->post('/shipping/book', ['ids' => [$order->id]]);
        $this->post('/shipping/book', ['ids' => [$order->id]]); // no longer Confirmed: ignored

        $this->assertSame(1, DB::table('shipments')->where('order_id', $order->id)->count());
    }

    public function test_reprint_after_address_change_voids_the_old_label(): void
    {
        $order = $this->confirmedOrder();
        $this->post('/shipping/book', ['ids' => [$order->id]]);
        $order->refresh();

        app(OrderEditor::class)->request($order, ['items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]], 'ship_address' => 'New House 9'],
            DB::table('status_reasons')->where('reason_type', 'amendment')->where('system_key', 'customer_request')->value('id'), auth()->user(), $order->lock_version);
        $order->refresh();
        $this->assertSame(2, $order->current_version);
        $this->assertSame('reprint_label', DB::table('order_amendments')->where('order_id', $order->id)->value('courier_action'));

        // The new label is issued with the edit; no separate reprint step is needed.
        $this->assertNotNull(DB::table('shipment_labels')->where('barcode', $order->order_no.'-1')->value('voided_at'));
        $this->assertDatabaseHas('shipment_labels', ['barcode' => $order->order_no.'-2', 'voided_at' => null]);
        $this->assertSame(2, $order->fresh()->label_version);
    }
}
