<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\DeliveryCharges;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();

        $this->agent = User::factory()->create(['name' => 'Mahim']);
        $this->agent->roles()->attach($this->role(['orders.view' => 'own', 'orders.create', 'orders.edit'], [], 'Moderator')->id);
        app(\App\Services\PermissionService::class)->bump();

        Product::factory()->withVariant(600, ['sku' => 'DATE-500', 'weight_g' => 550])->create(['name' => 'Dates']);
        $this->variant = ProductVariant::firstWhere('sku', 'DATE-500');
    }

    private function zone(string $key): int
    {
        return (int) DB::table('delivery_zones')->where('system_key', $key)->value('id');
    }

    private function make(array $override = [], ?User $by = null, string $channel = 'messenger'): Order
    {
        return app(OrderService::class)->create($override + [
            'channel' => $channel, 'phone' => '01712345678', 'name' => 'Karim',
            'address_line' => 'House 1, Dhanmondi', 'zone_id' => $this->zone('inside_dhaka'),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
        ], $by ?? $this->agent);
    }

    public function test_chat_order_is_created_with_totals_snapshot_and_moderator(): void
    {
        $order = $this->make();

        $this->assertSame('IQ'.(10000 + $order->id), $order->order_no);
        $this->assertSame('new', OrderStatus::map()[$order->status_id]['key']);
        $this->assertSame($this->agent->id, $order->moderator_id);
        $this->assertSame('1200.00', $order->subtotal);
        $this->assertSame('90.00', $order->delivery_charge); // 1100 g inside Dhaka
        $this->assertSame('1290.00', $order->grand_total);
        $this->assertSame('1290.00', $order->cod_amount);
        $this->assertSame(1100, $order->total_weight_g);
        $this->assertSame('Dates', $order->items->first()->name_snapshot);
        $this->assertDatabaseHas('order_versions', ['order_id' => $order->id, 'version_no' => 1]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'from_status_id' => null]);
        $this->assertDatabaseHas('order_assignments', ['order_id' => $order->id, 'user_id' => $this->agent->id, 'how' => 'created']);
        $this->assertSame(1, (int) DB::table('customers')->where('primary_phone', '01712345678')->value('orders_count'));
    }

    public function test_agent_cannot_change_the_list_price_or_sell_out_of_stock(): void
    {
        $order = $this->make(['items' => [['variant_id' => $this->variant->id, 'qty' => 1, 'unit_price' => 1]]]);
        $this->assertSame('600.00', $order->items->first()->unit_price);

        $this->variant->update(['availability_status' => 'out_of_stock']);
        $this->expectException(ValidationException::class);
        $this->make(['phone' => '01812345678']);
    }

    public function test_discount_above_limit_needs_a_manager(): void
    {
        try {
            $this->make(['order_discount' => 500]);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order_discount', $e->errors());
        }

        $order = $this->make(['order_discount' => 500], $this->owner());
        $this->assertSame('500.00', $order->discount_total);
    }

    public function test_state_machine_rules_reasons_and_events(): void
    {
        $order = $this->make();
        $sm = app(OrderStateMachine::class);

        $sm->transition($order, 'record_verified', $this->owner(), 'rule');
        $this->assertNotNull($order->fresh()->verified_at);

        try {
            $sm->transition($order, 'cancelled', $this->agent);
            $this->fail('Cancel needs a reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason_id', $e->errors());
        }

        try {
            $sm->transition($order, 'packed', $this->agent);
            $this->fail('No jump to packed');
        } catch (ValidationException) {
        }

        $reason = DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'scheduled')->value('id');
        $sm->transition($order, 'hold', $this->agent, 'user', $reason);
        $this->assertSame($reason, $order->fresh()->hold_reason_id);

        $events = DB::table('order_events')->where('order_id', $order->id)->count();
        $this->assertSame(3, $events); // created, verified, hold
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'status']);
    }

    public function test_fulfilment_gate_needs_a_consignment_and_system_only_steps_refuse_users(): void
    {
        $order = $this->make();
        $sm = app(OrderStateMachine::class);
        $owner = $this->owner();
        $sm->transition($order, 'record_verified', $owner, 'rule');
        $sm->transition($order, 'confirmed', $this->agent);

        try {
            $sm->transition($order, 'ready_for_packaging', $owner, 'user');
            $this->fail('System only');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('set by the system', $e->getMessage());
        }

        try {
            $sm->transition($order, 'ready_for_packaging', null, 'system');
            $this->fail('No consignment');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('consignment', $e->getMessage());
        }
    }

    public function test_stale_lock_version_is_refused(): void
    {
        $order = $this->make();
        $stale = $order->lock_version;
        app(OrderStateMachine::class)->transition($order, 'record_verified', $this->owner(), 'rule');

        $this->expectException(ValidationException::class);
        app(OrderStateMachine::class)->transition($order, 'confirmed', $this->agent, 'user', null, null, $stale);
    }

    public function test_reassign_closes_previous_assignment_with_reason(): void
    {
        $order = $this->make();
        $other = User::factory()->create(['name' => 'Pranto']);
        $reason = DB::table('status_reasons')->where('reason_type', 'reassign')->where('system_key', 'moderator_error')->value('id');

        app(OrderService::class)->reassign($order, $this->owner(), $other, $reason);

        $this->assertSame($other->id, $order->fresh()->moderator_id);
        $this->assertNotNull(DB::table('order_assignments')->where('order_id', $order->id)->where('user_id', $this->agent->id)->value('ended_at'));
        $this->assertDatabaseHas('order_assignments', ['order_id' => $order->id, 'user_id' => $other->id, 'how' => 'reassigned', 'reason_id' => $reason]);
    }

    public function test_only_verified_advance_reduces_cod_and_trx_id_is_unique(): void
    {
        $bkash = DB::table('payment_methods')->where('system_key', 'bkash')->value('id');
        $order = $this->make(['advance' => ['method_id' => $bkash, 'amount' => 290, 'transaction_id' => 'TRX123']]);
        $this->assertSame('1290.00', $order->cod_amount);

        $payment = DB::table('order_payments')->where('order_id', $order->id)->value('id');
        app(OrderService::class)->verifyPayment($order, $payment, true, $this->owner());
        $order->refresh();
        $this->assertSame('1000.00', $order->cod_amount);
        $this->assertSame('partial_advance', $order->payment_status);

        $this->expectException(ValidationException::class);
        $this->make(['phone' => '01812345678', 'advance' => ['method_id' => $bkash, 'amount' => 100, 'transaction_id' => 'TRX123']]);
    }

    public function test_delivery_charge_rules_and_free_shipping_threshold(): void
    {
        $charges = app(DeliveryCharges::class);
        $outside = $this->zone('outside_dhaka');

        $this->assertSame(130.0, $charges->for($outside, 900, 1000));
        $this->assertSame(180.0, $charges->for($outside, 2500, 1000));

        DB::table('delivery_charge_rules')->insert(['zone_id' => null, 'min_weight_g' => 0, 'max_weight_g' => null, 'min_order_total' => 3000, 'charge' => 0, 'priority' => 100, 'is_active' => true]);
        DeliveryCharges::forget();
        $this->assertSame(0.0, $charges->for($outside, 900, 3500));
        $this->assertSame(130.0, $charges->for($outside, 900, 2999));
    }

    public function test_repeat_order_within_window_is_flagged_as_possible_duplicate(): void
    {
        $this->make();
        $second = $this->make();

        $this->assertTrue($second->is_duplicate_flag);
    }

    public function test_own_scope_sees_own_and_claimable_orders_only(): void
    {
        $mine = $this->make();
        $claimable = $this->make(['phone' => '01812345678'], null, 'web');
        $others = $this->make(['phone' => '01912345678'], $this->owner());

        $ids = Order::visibleTo($this->agent)->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertContains($claimable->id, $ids);
        $this->assertNotContains($others->id, $ids);
    }
}
