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

    public function test_confirm_points_wait_for_delivery_and_settle(): void
    {
        $order = $this->order();
        $this->move($order, 'confirmed', $this->agent);
        $this->assertSame(1.0, $this->points('pending'));

        $this->move($order, 'delivered', null);
        $this->assertSame(0.0, $this->points('pending'));
        $this->assertSame(4.0, $this->points('final')); // confirm +1, delivered +3
    }

    public function test_cancel_by_sales_mistake_revokes_confirm_and_takes_points(): void
    {
        $order = $this->order();
        $this->move($order, 'confirmed', $this->agent);
        $this->move($order, 'cancelled', $this->agent, 'entry_error');

        $this->assertSame(-2.0, $this->points('final'));
        $this->assertSame(1.0, (float) DB::table('point_ledger')->where('status', 'revoked')->sum('points'));
    }

    public function test_risky_order_delivered_earns_the_bonus_and_reversal_claws_it_back(): void
    {
        $order = $this->order();
        DB::table('verification_runs')->insert(['order_id' => $order->id, 'outcome' => 'manual_review', 'inputs_snapshot' => '{}', 'ran_by' => 'system']);
        $this->move($order, 'confirmed', $this->agent);
        $this->move($order, 'delivered', null);
        $this->assertSame(9.0, $this->points('final')); // +1 +3 +5

        // Courier corrects it to returned: delivery points go.
        $this->move($order, 'returned', null);
        $this->assertSame(1.0, $this->points('final'));
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
