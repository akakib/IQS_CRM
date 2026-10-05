<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Reports\KpiScorecard;
use App\Services\Reports\OrderProfit;
use App\Services\SettingsService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

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
        app(SettingsService::class)->set(['orders.packaging_cost' => 10, 'analysis.cod_fee_percent' => 1]);
    }

    /** 2 x 600 plus delivery, cost 400 each. */
    private function deliveredOrder(string $final = 'delivered'): Order
    {
        Product::factory()->withVariant(600, ['sku' => 'D-'.uniqid()])->create(['name' => 'Dates']);
        $order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'House 1',
            'zone_id' => (int) DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => ProductVariant::latest('id')->value('id'), 'qty' => 2]],
        ], $this->agent);
        DB::table('order_items')->where('order_id', $order->id)->update(['cost_price_snapshot' => 400]);
        foreach (['confirmed', $final] as $key) {
            DB::table('order_events')->insert(['order_id' => $order->id, 'to_status_id' => OrderStatus::idFor($key), 'source' => 'user', 'user_id' => $this->agent->id, 'created_at' => now()]);
        }
        $order->forceFill(['status_id' => OrderStatus::idFor($final)])->save();

        return $order;
    }

    public function test_profit_counts_revenue_cost_delivery_fee_and_packaging(): void
    {
        $a = $this->deliveredOrder();
        $b = $this->deliveredOrder('returned');
        $t = app(OrderProfit::class)->totals(today()->toDateString(), today()->toDateString());
        $cod = (float) $a->cod_amount;
        $delivery = (float) $a->delivery_charge + (float) $b->delivery_charge;

        $this->assertSame(2, $t['orders']);
        $this->assertSame(1, $t['delivered']);
        $this->assertEqualsWithDelta($cod, $t['revenue'], 0.01);
        $this->assertEqualsWithDelta(800, $t['cogs'], 0.01);
        $this->assertEqualsWithDelta($delivery, $t['delivery'], 0.01);   // a return still pays delivery
        $this->assertEqualsWithDelta($cod / 100, $t['cod_fee'], 0.01);
        $this->assertEqualsWithDelta($cod - 800 - $delivery - $cod / 100 - 20, $t['profit'], 0.01);
    }

    public function test_kpi_scores_the_order_owner(): void
    {
        $this->deliveredOrder();
        $rows = app(KpiScorecard::class)->rows(today()->toDateString(), today()->toDateString());

        $this->assertSame('Mahim', $rows[0]['name']);
        $this->assertSame(1, $rows[0]['confirmed']);
        $this->assertSame(100.0, $rows[0]['delivered_rate']);
    }

    public function test_pages_render_and_profit_is_masked(): void
    {
        $this->deliveredOrder();
        $owner = $this->owner();
        foreach (['day', 'channel', 'owner', 'product', 'category'] as $g) {
            $this->actingAs($owner)->get(route('analysis.index', ['group' => $g]))->assertOk()->assertSee('Profit before ads');
        }
        $this->get(route('kpi.index'))->assertOk()->assertSee('Mahim');
        $this->get(route('dashboard'))->assertOk()->assertSee('Orders today');

        $viewer = User::factory()->create();
        $viewer->roles()->attach($this->role(['analysis.view'], ['profit'], 'Viewer')->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($viewer)->get(route('analysis.index'))->assertOk()->assertDontSee('Profit before ads');
        $this->get(route('kpi.index'))->assertForbidden();
    }

    public function test_owner_summary_command_runs(): void
    {
        $this->deliveredOrder();
        $this->artisan('reports:owner-summary')->assertSuccessful();
    }
}
