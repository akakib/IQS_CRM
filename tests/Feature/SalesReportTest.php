<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Reports\SalesReport;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesReportTest extends TestCase
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
        Product::factory()->withVariant(500)->create(['name' => 'Dates']);
        $this->agent = User::factory()->create(['name' => 'Mahim']);
        $this->agent->roles()->attach($this->role(['orders.view' => 'own', 'orders.create', 'orders.edit'], [], 'Moderator')->id);
    }

    /** An order placed on $placed that ended with $final on $ended (null = still open). */
    private function order(string $placed, ?string $final = null, ?string $ended = null): Order
    {
        $order = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'House 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 2]],
        ], $this->agent);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $placed.' 10:00:00']);
        if ($final) {
            DB::table('order_events')->insert(['order_id' => $order->id, 'to_status_id' => OrderStatus::idFor($final), 'source' => 'webhook', 'created_at' => $ended.' 15:00:00']);
            $order->forceFill(['status_id' => OrderStatus::idFor($final)])->save();
        }

        return $order;
    }

    public function test_daily_series_counts_placed_completed_cancelled_and_returned_on_the_right_days(): void
    {
        $this->order('2026-10-01');                                   // placed, still open
        $this->order('2026-10-01', 'delivered', '2026-10-03');        // placed 1st, delivered 3rd
        $this->order('2026-10-02', 'partial_delivered', '2026-10-03');
        $this->order('2026-10-02', 'cancelled', '2026-10-02');
        $this->order('2026-10-03', 'returned', '2026-10-05');
        $this->order('2026-09-20', 'delivered', '2026-10-04');        // placed outside the range, delivered inside

        $r = app(SalesReport::class)->build('2026-10-01', '2026-10-05');
        $this->assertFalse($r['weekly']);
        $this->assertCount(5, $r['rows'], 'one row per day, empty days included');
        $byDay = collect($r['rows'])->keyBy('day');

        $this->assertSame(2, $byDay['2026-10-01']['placed']);
        $this->assertSame(2, $byDay['2026-10-02']['placed']);
        $this->assertSame(1, $byDay['2026-10-02']['cancelled']);
        $this->assertSame(2, $byDay['2026-10-03']['completed']);
        $this->assertSame(1, $byDay['2026-10-04']['completed']);
        $this->assertSame(1, $byDay['2026-10-05']['returned']);
        $this->assertSame(0, $byDay['2026-10-04']['placed']);
        $this->assertEquals(2000.0, $byDay['2026-10-01']['placed_amount'], '2 orders × 2 × 500');
        $this->assertEquals(2000.0, $byDay['2026-10-03']['completed_amount'], 'COD of the two orders delivered that day');

        $t = $r['totals'];
        $this->assertSame(5, $t['placed']);
        $this->assertSame(3, $t['completed']);
        $this->assertSame(1, $t['cancelled']);
        $this->assertSame(1, $t['returned']);
        $this->assertEquals(60.0, $t['completion_rate'], '3 of 5 ended orders');
    }

    public function test_a_single_day_is_shown_per_hour(): void
    {
        $a = $this->order('2026-10-02');
        $b = $this->order('2026-10-02', 'cancelled', '2026-10-02');
        DB::table('orders')->where('id', $a->id)->update(['created_at' => '2026-10-02 09:15:00']);
        DB::table('orders')->where('id', $b->id)->update(['created_at' => '2026-10-02 21:40:00']);

        $r = app(SalesReport::class)->build('2026-10-02', '2026-10-02');
        $this->assertTrue($r['hourly']);
        $this->assertCount(24, $r['rows']);
        $byHour = collect($r['rows'])->keyBy('label');
        $this->assertSame(1, $byHour['09:00']['placed']);
        $this->assertSame(1, $byHour['21:00']['placed']);
        $this->assertSame(0, $byHour['10:00']['placed']);
        $this->assertSame(1, $byHour['15:00']['cancelled'], 'ended events are stamped 15:00 by the helper');
        $this->assertSame(2, $r['totals']['placed']);

        $viewer = User::factory()->create();
        $viewer->roles()->attach($this->role(['reports.view'], [], 'Viewer')->id);
        $this->actingAs($viewer)->get('/reports/sales?from=2026-10-02&to=2026-10-02')->assertOk()
            ->assertSee('Orders per hour')->assertSee('Busiest hour')->assertSee('21:00');
    }

    public function test_channel_breakdown_and_filter(): void
    {
        $this->order('2026-10-02', 'delivered', '2026-10-03');           // web
        $this->order('2026-10-02');                                       // web, open
        $chat = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01799999999', 'name' => 'Rina', 'address_line' => 'House 2',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]],
        ], $this->agent);
        DB::table('orders')->where('id', $chat->id)->update(['created_at' => '2026-10-03 10:00:00']);
        DB::table('order_events')->insert(['order_id' => $chat->id, 'to_status_id' => OrderStatus::idFor('cancelled'), 'source' => 'user', 'created_at' => '2026-10-03 12:00:00']);

        $r = app(SalesReport::class)->build('2026-10-01', '2026-10-05');
        $byChannel = collect($r['channels'])->keyBy('channel');
        $this->assertSame(['web', 'messenger'], array_keys($byChannel->all()), 'busiest channel first, empty channels left out');
        $this->assertSame(2, $byChannel['web']['placed']);
        $this->assertSame(1, $byChannel['web']['completed']);
        $this->assertSame(1, $byChannel['messenger']['placed']);
        $this->assertSame(1, $byChannel['messenger']['cancelled']);
        $this->assertEquals(66.7, $byChannel['web']['share']);

        // The filter narrows the whole report to one channel.
        $only = app(SalesReport::class)->build('2026-10-01', '2026-10-05', 'messenger');
        $this->assertSame(1, $only['totals']['placed']);
        $this->assertSame(0, $only['totals']['completed']);
        $this->assertSame(1, $only['totals']['cancelled']);
        $this->assertCount(1, $only['channels']);

        $viewer = User::factory()->create();
        $viewer->roles()->attach($this->role(['reports.view'], [], 'Viewer')->id);
        $this->actingAs($viewer)->get('/reports/sales?from=2026-10-01&to=2026-10-05')->assertOk()->assertSee('Orders by channel')->assertSee('Messenger');
        $this->actingAs($viewer)->get('/reports/sales?from=2026-10-01&to=2026-10-05&channel=messenger')->assertOk()->assertSee('2026-10-01 → 2026-10-05 · Messenger');
        $this->actingAs($viewer)->get('/reports/sales?channel=nope')->assertOk();
    }

    public function test_long_ranges_are_grouped_per_week_and_capped(): void
    {
        $r = app(SalesReport::class)->build('2026-01-01', '2026-06-30');
        $this->assertTrue($r['weekly']);
        $this->assertLessThan(30, count($r['rows']));

        $r = app(SalesReport::class)->build('2020-01-01', '2026-06-30');
        $this->assertSame('2025-06-30', $r['from'], 'at most a year');
    }

    public function test_sales_page_needs_the_permission_and_shows_the_graph_and_totals(): void
    {
        $this->order('2026-10-02', 'delivered', '2026-10-03');
        $this->actingAs($this->agent)->get('/reports/sales')->assertForbidden();

        $viewer = User::factory()->create();
        $viewer->roles()->attach($this->role(['reports.view'], [], 'Viewer')->id);
        $this->actingAs($viewer)->get('/reports/sales?from=2026-10-01&to=2026-10-05')->assertOk()
            ->assertSee('Orders placed')->assertSee('<polyline', false)->assertSee('03 Oct');

        // Dashboard shows the 14-day graph only with the permission.
        $this->actingAs($viewer)->get('/dashboard')->assertOk()->assertSee('Last 14 days');
        $this->actingAs($this->agent)->get('/dashboard')->assertOk()->assertDontSee('Last 14 days');
    }
}
