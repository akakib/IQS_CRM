<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Points\PointHooks;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\PointsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PointsTest extends TestCase
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
        (new PointsSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();

        $this->agent = User::factory()->create(['name' => 'Mahim']);
        $this->agent->roles()->attach($this->role(['orders.view' => 'own', 'orders.create', 'orders.edit'], [], 'Moderator')->id);

        Product::factory()->withVariant(600, ['sku' => 'DATE-500'])->create(['name' => 'Dates']);
        $this->variant = ProductVariant::firstWhere('sku', 'DATE-500');
    }

    private function order(): Order
    {
        return app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'House 1, Dhanmondi',
            'zone_id' => (int) DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], $this->agent);
    }

    /** Move the order and fire the points hook the way the state machine does. */
    private function move(Order $order, string $key, ?User $by, ?string $reasonKey = null): void
    {
        $from = OrderStatus::map()[$order->status_id];
        $order->forceFill(['status_id' => OrderStatus::idFor($key)])->save();
        $reason = $reasonKey ? DB::table('status_reasons')->where('system_key', $reasonKey)->value('id') : null;
        DB::table('order_events')->insert([
            'order_id' => $order->id, 'from_status_id' => $from['id'], 'to_status_id' => $order->status_id, 'user_id' => $by?->id,
            'source' => $by ? 'user' : 'webhook', 'reason_id' => $reason, 'created_at' => now(),
        ]);
        app(PointHooks::class)->transition($order, $from, OrderStatus::map()[$order->status_id], $by);
    }

    private function points(string $status): float
    {
        return (float) DB::table('point_ledger')->where('user_id', $this->agent->id)->where('status', $status)->sum('points');
    }

    public function test_only_the_outcome_scores_and_a_saved_order_earns_more(): void
    {
        $order = $this->order();
        $this->move($order, 'confirmed', $this->agent);
        $this->assertSame(0.0, $this->points('pending') + $this->points('final')); // nothing for taking or confirming

        $this->move($order, 'delivered', null);
        $this->assertSame(3.0, $this->points('final'));

        // Had a No response or Hold on the way, then delivered: 3 + 2 bonus.
        // (It is the same customer again, entered by the moderator: + 2 for the follow-up sale.)
        $saved = $this->order();
        $saved->forceFill(['had_setback' => true])->save();
        $this->move($saved, 'delivered', null);
        $this->assertSame(3.0 + 3 + 2 + 2, $this->points('final'));
    }

    public function test_every_cancel_costs_the_moderator_a_point_whatever_the_reason(): void
    {
        $order = $this->order();
        $this->move($order, 'cancelled', $this->agent, 'entry_error');
        $this->assertSame(-1.0, $this->points('final'));

        $other = $this->order();
        $this->move($other, 'cancelled', $this->agent, 'customer_cancelled');
        $this->assertSame(-2.0, $this->points('final'));
    }

    public function test_admin_can_pay_more_for_a_channel_and_a_reversed_delivery_is_clawed_back(): void
    {
        $rule = DB::table('point_rules')->insertGetId(['trigger_key' => 'order_delivered', 'name' => 'Chat order bonus', 'points' => 2, 'recipient' => 'order_moderator',
            'settle_on' => 'order_final', 'requires_delivery' => false, 'is_active' => true, 'sort_order' => 99, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('point_rule_conditions')->insert(['rule_id' => $rule, 'field' => 'channel', 'operator' => '=', 'value' => 'messenger']);

        $order = $this->order(); // a Messenger order
        $this->move($order, 'delivered', null);
        $this->assertSame(5.0, $this->points('final'));

        // Courier corrects it to returned: delivery points go.
        $this->move($order, 'returned', null);
        $this->assertSame(0.0, $this->points('final'));
    }

    public function test_follow_up_sale_to_a_repeat_customer_earns_extra(): void
    {
        $first = $this->order();               // same phone, so the same customer
        $this->move($first, 'delivered', null);
        $this->assertSame(3.0, $this->points('final'));

        $this->travel(5)->days();
        $again = $this->order();               // entered by the moderator on Messenger
        $this->move($again, 'delivered', null);
        $this->assertSame(8.0, $this->points('final')); // 3 + 2 for the follow-up sale
    }

    public function test_extra_time_costs_half_a_point(): void
    {
        app(PointHooks::class)->timerExtended($this->order(), $this->agent->id, 1);
        $this->assertSame(-0.5, $this->points('final'));
    }

    public function test_missed_timer_costs_a_point_and_more_after_three_in_a_day(): void
    {
        $hooks = app(PointHooks::class);
        $hooks->timerMissed($this->order(), $this->agent->id, 1);
        $this->assertSame(-1.0, $this->points('final'));

        $hooks->timerMissed($this->order(), $this->agent->id, 4);
        $this->assertSame(-3.0, $this->points('final')); // -1 and -1 more for the 4th today
    }

    public function test_monthly_minus_cap_limits_losses(): void
    {
        app(\App\Services\SettingsService::class)->set(['points.monthly_negative_cap' => 12]);
        app(PointHooks::class)->fakeStatus(null, $this->agent->id);
        app(PointHooks::class)->fakeStatus(null, $this->agent->id);

        $this->assertSame(-12.0, $this->points('final'));
    }

    public function test_manager_confirms_flag_and_decides_a_dispute(): void
    {
        $owner = $this->owner();
        $order = $this->order();
        $flag = DB::table('integrity_flags')->insertGetId(['order_id' => $order->id, 'user_id' => $this->agent->id, 'flag_type' => 'too_fast_confirm', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->post(route('points.review.flag', $flag), ['decision' => 'confirmed'])->assertSessionHas('success');
        $this->assertSame(-10.0, $this->points('final'));

        $entry = DB::table('point_ledger')->where('user_id', $this->agent->id)->value('id');
        $this->actingAs($this->agent)->post(route('points.dispute', $entry), ['note' => 'I did call'])->assertSessionHas('success');
        $this->actingAs($owner)->post(route('points.review.dispute', $entry), ['decision' => 'upheld'])->assertSessionHas('success');
        $this->assertSame(0.0, $this->points('final'));
    }

    public function test_pages_render_and_rule_value_is_editable(): void
    {
        $owner = $this->owner();
        $rule = DB::table('point_rules')->where('trigger_key', 'order_delivered')->value('id');

        $this->actingAs($owner)->get(route('settings.points'))->assertOk()->assertSee('Order delivered');
        $this->put(route('settings.points.update', $rule), ['points' => 4, 'is_active' => 1])->assertSessionHas('success');
        $this->assertSame(4.0, (float) DB::table('point_rules')->where('id', $rule)->value('points'));

        $this->get(route('points.review', ['tab' => 'qa']))->assertOk();
        $this->get(route('points.review', ['tab' => 'disputes']))->assertOk();
        $this->actingAs($this->agent)->get(route('points.mine'))->assertOk()->assertSee('My points');
        $this->get(route('settings.points'))->assertForbidden();
    }
}
