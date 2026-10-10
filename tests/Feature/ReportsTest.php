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

    public function test_profit_takes_off_refunds_and_return_losses_and_credit_stays_with_the_confirmer(): void
    {
        $range = [today()->toDateString(), today()->toDateString()];
        $b = $this->deliveredOrder('returned');
        $base = app(OrderProfit::class)->totals(...$range);

        // A returned order: ৳300 advance paid, then given back; a ৳60 courier charge; one of two items damaged (cost 400).
        $bkash = (int) DB::table('payment_methods')->where('system_key', 'bkash')->value('id');
        DB::table('order_payments')->insert([
            ['order_id' => $b->id, 'payment_type' => 'advance', 'method_id' => $bkash, 'amount' => 300, 'status' => 'verified', 'transaction_id' => 'P1', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $b->id, 'payment_type' => 'refund', 'method_id' => $bkash, 'amount' => 300, 'status' => 'verified', 'transaction_id' => 'P2', 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('orders')->where('id', $b->id)->update(['advance_verified' => 300, 'return_charge' => 60]);
        $item = DB::table('order_items')->where('order_id', $b->id)->first();
        DB::table('return_items')->insert(['order_id' => $b->id, 'order_item_id' => $item->id, 'variant_id' => $item->variant_id, 'qty' => 1, 'condition' => 'damaged', 'created_at' => now()]);

        $t = app(OrderProfit::class)->totals(...$range);
        $this->assertEqualsWithDelta($base['revenue'], $t['revenue'], 0.01);            // the ৳300 came in and went back: no revenue
        $this->assertEqualsWithDelta(60 + 400, $t['returns'], 0.01);                    // courier charge + the damaged item at cost
        $this->assertEqualsWithDelta($base['profit'] - 460, $t['profit'], 0.01);

        // Reassigned later: the profit stays with the person who confirmed it.
        $other = User::factory()->create(['name' => 'Rafi']);
        DB::table('orders')->where('id', $b->id)->update(['confirmed_by' => $this->agent->id, 'moderator_id' => $other->id]);
        $rows = collect(app(OrderProfit::class)->grouped('moderator', ...$range, ...[null, 50])->items());
        $this->assertSame([$this->agent->id], $rows->pluck('g')->map(fn ($g) => (int) $g)->all());
    }

    public function test_an_edit_keeps_the_frozen_cost_and_a_missing_cost_is_flagged(): void
    {
        $a = $this->deliveredOrder();
        $a->forceFill(['confirmed_at' => now()])->save();
        // The editor's proposed lines carry no cost (as buildItems makes them).
        $items = DB::table('order_items')->where('order_id', $a->id)->get()
            ->map(fn ($i) => collect((array) $i)->except(['id', 'order_id', 'cost_price_snapshot', 'created_at', 'updated_at'])->all())->all();
        // An edit re-creates the items (here the same lines): the cost frozen at confirmation must survive.
        $amendment = DB::table('order_amendments')->insertGetId([
            'order_id' => $a->id, 'requested_by' => $this->agent->id, 'reason_id' => (int) DB::table('status_reasons')->where('reason_type', 'amendment')->value('id'),
            'from_version' => 1, 'status_at_time_id' => $a->status_id, 'edit_class' => 'label', 'changes' => '{}', 'created_at' => now(), 'updated_at' => now(),
            'proposed' => json_encode(['items' => $items, 'shipping' => [], 'totals' => []]),
        ]);
        (fn () => $this->apply($a, $amendment, \App\Models\User::first()))->call(app(\App\Services\Orders\OrderEditor::class));
        $this->assertEquals([400], DB::table('order_items')->where('order_id', $a->id)->pluck('cost_price_snapshot')->map(fn ($c) => (float) $c)->unique()->values()->all());

        DB::table('order_items')->where('order_id', $a->id)->update(['cost_price_snapshot' => null]);
        $this->assertSame(1, app(OrderProfit::class)->missingCost(today()->toDateString(), today()->toDateString()));
    }

    public function test_kpi_scores_the_order_moderator(): void
    {
        $this->deliveredOrder();
        $data = app(KpiScorecard::class)->build(today()->toDateString(), today()->toDateString());

        $this->assertSame('Mahim', $data['rows'][0]['name']);
        $this->assertSame(1, $data['rows'][0]['delivered']);
        $this->assertSame(100.0, $data['rows'][0]['delivery_rate']);
        $this->assertSame(100.0, $data['team']['delivery_rate']);
    }

    public function test_pages_render_and_profit_is_masked(): void
    {
        $this->deliveredOrder();
        $owner = $this->owner();
        foreach (['day', 'channel', 'moderator', 'product', 'category'] as $g) {
            $this->actingAs($owner)->get(route('analysis.index', ['group' => $g]))->assertOk()->assertSee('Profit before ads');
        }
        $this->get(route('kpi.index'))->assertOk()->assertSee('Mahim');
        $this->get(route('dashboard'))->assertOk()->assertSee('Orders today');

        $viewer = User::factory()->create();
        $viewer->roles()->attach($this->role(['analysis.view'], ['profit'], 'Viewer')->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($viewer)->get(route('analysis.index'))->assertOk()->assertDontSee('Profit before ads');
        // KPI: without kpi.view a person sees only their own row, never the team.
        $this->get(route('kpi.index'))->assertOk()->assertDontSee('Mahim');
    }

    public function test_owner_summary_command_runs(): void
    {
        $this->deliveredOrder();
        $this->artisan('reports:owner-summary')->assertSuccessful();
    }
}
