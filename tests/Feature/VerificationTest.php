<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\VerificationEngine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ConfirmationSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new ConfirmationSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        Product::factory()->withVariant(500, ['sku' => 'ALM', 'weight_g' => 500, 'cost_price' => 350])->create(['name' => 'Almonds']);
        $this->variant = ProductVariant::firstWhere('sku', 'ALM');
    }

    private function order(string $phone, int $qty = 1, array $extra = []): Order
    {
        $order = app(OrderService::class)->create($extra + [
            'channel' => 'web', 'phone' => $phone, 'name' => 'Buyer', 'address_line' => 'Road 1', 'thana' => 'Mirpur',
            'zone_id' => DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->variant->id, 'qty' => $qty]],
        ], null, 'webhook');
        app(VerificationEngine::class)->run($order);

        return $order->fresh();
    }

    private function key(Order $order): string
    {
        return OrderStatus::map()[$order->status_id]['key'];
    }

    public function test_good_courier_history_is_record_verified_and_the_reason_is_stored(): void
    {
        $order = $this->order('01712345678'); // fake: 13 parcels, 12 delivered

        $this->assertSame('record_verified', $this->key($order));
        $run = DB::table('verification_runs')->where('order_id', $order->id)->first();
        $this->assertSame('Good Steadfast history', DB::table('verification_rules')->where('id', $run->matched_rule_id)->value('name'));
        $inputs = json_decode($run->inputs_snapshot, true);
        $this->assertFalse($inputs['is_new_customer']);
        $this->assertEquals(92.31, collect($inputs['providers'])->firstWhere('key', 'steadfast')['success_rate']);
    }

    public function test_new_customer_small_order_verified_big_order_held_for_advance(): void
    {
        $small = $this->order('01712345679', 1);   // ends in 9: no history anywhere
        $this->assertSame('record_verified', $this->key($small));

        $big = $this->order('01812345679', 4);     // 2000 + delivery > 1500
        $this->assertSame('hold', $this->key($big));
        $this->assertSame('advance_wait', DB::table('status_reasons')->where('id', $big->hold_reason_id)->value('system_key'));
    }

    public function test_risky_history_falls_through_to_manual_review(): void
    {
        $order = $this->order('01700000000'); // 37.5% on 8 parcels

        $this->assertSame('new', $this->key($order));
        $this->assertSame('manual_review', DB::table('verification_runs')->where('order_id', $order->id)->value('outcome'));
    }

    public function test_trusted_repeat_customer_is_auto_confirmed_and_cost_is_frozen(): void
    {
        $customer = Customer::create(['name' => 'Loyal', 'primary_phone' => '01912345670']);
        DB::table('customer_phones')->insert(['customer_id' => $customer->id, 'phone' => '01912345670', 'is_primary' => true]);
        $customer->update(['delivered_count' => 3]);

        $order = $this->order('01912345670');

        $this->assertSame('confirmed', $this->key($order));
        $this->assertSame('350.00', $order->items->first()->cost_price_snapshot);
        $this->assertSame(2, DB::table('order_events')->where('order_id', $order->id)->where('source', 'rule')->count());
    }

    public function test_blocked_customer_always_goes_to_manual_review(): void
    {
        $customer = Customer::create(['name' => 'Blocked', 'primary_phone' => '01912345678', 'risk_level' => 'blocked']);
        DB::table('customer_phones')->insert(['customer_id' => $customer->id, 'phone' => '01912345678', 'is_primary' => true]);
        $customer->update(['delivered_count' => 9]);

        $this->assertSame('new', $this->key($this->order('01912345678')));
    }

    public function test_confirming_with_pre_order_item_holds_and_counts_against_the_limit(): void
    {
        $this->variant->update(['availability_status' => 'backorder', 'backorder_limit_qty' => 2, 'expected_restock_date' => now()->addDays(5)->toDateString()]);
        $order = $this->order('01712345678', 2);
        $this->assertSame('record_verified', $this->key($order));

        app(OrderStateMachine::class)->transition($order, 'confirmed', $this->owner());

        $order->refresh();
        $this->assertSame('hold', $this->key($order));
        $this->assertSame('awaiting_stock', DB::table('status_reasons')->where('id', $order->hold_reason_id)->value('system_key'));
        $this->assertNotNull($order->hold_expected_date);
        $this->variant->refresh();
        $this->assertSame('2.000', $this->variant->backorder_taken_qty);
        $this->assertSame('out_of_stock', $this->variant->availability_status); // limit reached
    }

    public function test_rules_screen_adds_a_rule_and_tests_an_order(): void
    {
        $order = $this->order('01712345678');
        $this->actingAs($this->owner());

        $this->get('/settings/verification')->assertOk()->assertSee('Good Steadfast history');
        $this->post('/settings/verification', [
            'name' => 'Big web orders need advance', 'priority' => 5, 'outcome' => 'hold_for_advance', 'applies_to_channel' => 'web',
            'conditions' => [['field' => 'order_total', 'operator' => '>', 'value' => '400']],
        ])->assertSessionHas('success');

        $this->post('/settings/verification/test', ['order_no' => $order->order_no])
            ->assertSessionHas('verification_test', fn ($t) => $t['rule'] === 'Big web orders need advance' && $t['outcome'] === 'hold_for_advance');
        $this->assertSame('record_verified', $this->key($order->fresh())); // a test changes nothing
    }
}
