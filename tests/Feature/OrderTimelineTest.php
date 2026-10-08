<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\DeskService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\OrderTimeline;
use App\Services\Work\WorkCalendar;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Time measured without a countdown: from the order's own history. */
class OrderTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $mahim;

    private User $rima;

    private ProductVariant $variant;

    private int $phone = 1712345100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00')); // a Monday inside office hours
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        OrderStatus::forget();
        $role = $this->role(['orders.view' => 'own', 'orders.create', 'orders.edit', 'orders.take'], [], 'Moderator');
        $this->mahim = User::factory()->create(['name' => 'Mahim']);
        $this->rima = User::factory()->create(['name' => 'Rima']);
        $this->mahim->roles()->attach($role->id);
        $this->rima->roles()->attach($role->id);
        app(\App\Services\PermissionService::class)->bump();
        Product::factory()->withVariant(600, ['sku' => 'DATE-500'])->create(['name' => 'Dates']);
        $this->variant = ProductVariant::firstWhere('sku', 'DATE-500');
    }

    private function web(): Order
    {
        return app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '0'.($this->phone++), 'name' => 'Customer', 'address_line' => 'Road 1',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], null, 'webhook');
    }

    public function test_without_a_countdown_open_first_action_and_net_time_are_recorded(): void
    {
        $this->assertFalse((bool) settings('desk.timer_enabled')); // off by default
        $o = $this->web();
        $this->travel(2)->minutes();
        $this->actingAs($this->mahim)->post('/desk/next');
        $this->assertNull($o->fresh()->action_due_at); // no countdown

        $this->travel(5)->minutes();
        $this->get('/desk?tab=verify&order='.$o->id)->assertOk();
        $this->travel(3)->minutes();
        $this->get('/desk?tab=verify&order='.$o->id); // opening again keeps the first time
        $turn = DB::table('order_assignments')->where('order_id', $o->id)->first();
        $this->assertSame('2026-10-05 10:07:00', $turn->first_opened_at);
        $this->assertNull($turn->first_action_at);

        // Verify, call: no answer, back in 30 minutes. Those 30 are the customer's; once it is back, the time is Mahim's again.
        $machine = app(OrderStateMachine::class);
        $machine->transition($o->fresh(), 'record_verified', $this->mahim);
        $this->assertSame('2026-10-05 10:10:00', DB::table('order_assignments')->where('order_id', $o->id)->value('first_action_at'));
        $this->travel(4)->minutes();
        app(DeskService::class)->noResponse($o->fresh(), $this->mahim); // 10:14, back at 10:44
        $this->travel(36)->minutes();
        $machine->transition($o->fresh(), 'confirmed', $this->mahim); // called again at 10:50: confirmed

        // Given 10:02, confirmed 10:50: 48 minutes, of which 30 waiting on the customer = 18 net.
        $story = app(OrderTimeline::class)->forOrder($o->id);
        $this->assertCount(1, $story['turns']);
        $t = $story['turns'][0];
        $this->assertSame('Mahim', $t['name']);
        $this->assertSame('confirmed', $t['sent_to']);
        $this->assertSame(18 * 60, $t['net_seconds']);
        $this->assertSame('2026-10-05 10:50:00', $t['sent']->toDateTimeString());
    }

    public function test_an_order_left_untouched_for_an_hour_goes_back_and_a_worked_one_stays(): void
    {
        $left = $this->web();
        $worked = $this->web();
        app(\App\Services\SettingsService::class)->set(['desk.active_limit' => 2]);
        app(DeskService::class)->takeNext($this->mahim);

        $this->travel(40)->minutes();
        app(OrderStateMachine::class)->transition($worked->fresh(), 'record_verified', $this->mahim); // touched at 10:40
        $this->travel(21)->minutes();
        $this->assertSame(1, app(DeskService::class)->returnIdle());

        $this->assertNull($left->fresh()->moderator_id); // 61 minutes with nothing done: back to New
        $this->assertDatabaseHas('order_assignments', ['order_id' => $left->id, 'user_id' => $this->mahim->id, 'ended_reason' => 'idle']);
        $this->assertSame($this->mahim->id, $worked->fresh()->moderator_id);
        // Counted where the old timeouts were: Control room shows it as given back.
        $this->assertSame(1, DB::table('order_assignments')->whereIn('ended_reason', ['timeout', 'idle'])->count());

        // A new turn is measured from its own start: Rima's time does not include Mahim's hour.
        $this->assertSame($left->id, app(DeskService::class)->takeNext($this->rima)->id);
        $this->travel(5)->minutes();
        app(OrderStateMachine::class)->transition($left->fresh(), 'record_verified', $this->rima);
        $this->travel(5)->minutes();
        app(OrderStateMachine::class)->transition($left->fresh(), 'confirmed', $this->rima);
        $turns = app(OrderTimeline::class)->forOrder($left->id)['turns'];
        $this->assertSame(['Mahim', 'Rima'], array_column($turns, 'name'));
        $this->assertSame('idle', $turns[0]['ended_reason']);
        $this->assertNull($turns[0]['net_seconds']);
        $this->assertSame(10 * 60, $turns[1]['net_seconds']);
    }

    public function test_a_night_shift_past_midnight_is_still_working_time(): void
    {
        foreach (range(0, 6) as $day) {
            DB::table('work_schedules')->insert(['user_id' => $this->rima->id, 'weekday' => $day, 'start_time' => '20:00:00', 'end_time' => '09:00:00']);
        }
        $calendar = app(WorkCalendar::class);
        $this->assertTrue($calendar->isWorking($this->rima->id, Carbon::parse('2026-10-06 01:00:00')));
        $this->assertTrue($calendar->isWorking($this->rima->id, Carbon::parse('2026-10-05 22:00:00')));
        $this->assertFalse($calendar->isWorking($this->rima->id, Carbon::parse('2026-10-05 12:00:00')));
        // A No answer return at 1 AM stays at 1 AM for her, not moved to the evening.
        $this->assertSame('2026-10-06 01:30:00', $calendar->nextWorkingMoment($this->rima->id, Carbon::parse('2026-10-06 01:30:00'))->toDateTimeString());
        $this->assertSame('2026-10-06 20:00:00', $calendar->nextWorkingMoment($this->rima->id, Carbon::parse('2026-10-06 12:00:00'))->toDateTimeString());
    }
}
