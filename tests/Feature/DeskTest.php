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
use App\Services\Work\BreakService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\PointsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeskTest extends TestCase
{
    use RefreshDatabase;

    private User $mahim;

    private User $rima;

    private ProductVariant $variant;

    private int $phone = 1712345000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00')); // a Monday inside office hours
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new PointsSeeder)->run();
        Artisan::call('notifications:sync');
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

    private function key(Order $o): string
    {
        return OrderStatus::map()[$o->fresh()->status_id]['key'];
    }

    private function desk(): DeskService
    {
        return app(DeskService::class);
    }

    private function act(Order $o, string $action, array $extra = [])
    {
        return $this->post("/desk/{$o->id}/act", ['action' => $action, 'lock_version' => $o->fresh()->lock_version] + $extra);
    }

    public function test_take_next_gives_the_oldest_order_and_stops_at_the_limit(): void
    {
        app(\App\Services\SettingsService::class)->set(['desk.active_limit' => 2]);
        [$a, $b, $c] = [$this->web(), $this->web(), $this->web()];

        $this->actingAs($this->mahim)->post('/desk/next')->assertSessionHas('success');
        $this->assertSame($this->mahim->id, $a->fresh()->moderator_id); // oldest first, not picked
        $this->assertNull($b->fresh()->moderator_id);

        // A double click does not hand out a second order.
        $this->post('/desk/next')->assertRedirect(route('desk.index'));
        $this->assertNull($b->fresh()->moderator_id);

        $this->travel(4)->seconds();
        $this->post('/desk/next');
        $this->assertSame($this->mahim->id, $b->fresh()->moderator_id);

        $this->travel(4)->seconds();
        $this->post('/desk/next')->assertSessionHasErrors('order'); // holds 2 of 2
        $this->assertNull($c->fresh()->moderator_id);

        $this->get('/desk')->assertOk()->assertSee($a->order_no)->assertSee('Finish one first');
    }

    public function test_timer_runs_on_one_order_at_a_time_and_a_miss_releases_it_with_a_penalty(): void
    {
        [$a, $b] = [$this->web(), $this->web()];
        $this->desk()->takeNext($this->mahim);
        $this->desk()->takeNext($this->mahim);

        $this->assertNotNull($a->fresh()->action_due_at); // the oldest has the clock
        $this->assertNull($b->fresh()->action_due_at);

        $this->travel(11)->minutes();
        Artisan::call('desk:tick');

        $this->assertNull($a->fresh()->moderator_id); // back in New
        $this->assertNotNull($b->fresh()->action_due_at); // the next timer started
        $this->assertDatabaseHas('order_assignments', ['order_id' => $a->id, 'user_id' => $this->mahim->id, 'ended_reason' => 'timeout']);
        $this->assertSame(-1.0, (float) DB::table('point_ledger')->where('user_id', $this->mahim->id)->sum('points'));

        // Someone else pressing Take next gets the released order (oldest first).
        $this->assertSame($a->id, $this->desk()->takeNext($this->rima)->id);
    }

    public function test_acting_in_time_stops_the_timer(): void
    {
        $a = $this->web();
        $this->actingAs($this->mahim)->post('/desk/next');
        $this->act($a, 'verify')->assertSessionHas('success');

        $this->assertSame('record_verified', $this->key($a));
        $this->assertNotNull($a->fresh()->action_due_at); // still mine to call: a fresh 10 minutes
        $this->travel(5)->minutes();
        $this->act($a, 'confirm', ['note' => 'will take it'])->assertSessionHas('success');

        $this->assertSame('confirmed', $this->key($a));
        $this->assertNull($a->fresh()->action_due_at);
        $this->assertDatabaseHas('order_notes', ['order_id' => $a->id, 'note_type' => 'call']);
        $this->travel(30)->minutes();
        Artisan::call('desk:tick');
        $this->assertSame($this->mahim->id, $a->fresh()->moderator_id);
    }

    public function test_no_response_returns_after_each_delay_then_cancels(): void
    {
        $a = $this->web();
        app(OrderStateMachine::class)->transition($a, 'record_verified', null, 'rule');
        $this->actingAs($this->mahim)->post('/desk/next');

        $this->act($a, 'no_response');
        $this->assertSame('no_answer', $this->key($a));
        $this->assertTrue($a->fresh()->next_call_at->equalTo(now()->addMinutes(30)));
        $this->assertSame($this->mahim->id, $a->fresh()->moderator_id); // stays with the same moderator
        $this->assertTrue($a->fresh()->had_setback);
        $this->get('/desk?tab=again')->assertSee($a->order_no);

        // 30 minutes later it is back in the Call tab, with a timer.
        $this->travel(31)->minutes();
        Artisan::call('desk:tick');
        $this->assertNotNull($a->fresh()->action_due_at);
        $this->get('/desk?tab=call')->assertSee($a->order_no);

        $this->act($a, 'no_response');
        $this->assertTrue($a->fresh()->next_call_at->equalTo(now()->addMinutes(300)));

        $this->travelTo($a->fresh()->next_call_at->copy()->addMinute());
        $this->act($a, 'no_response'); // third: comes back after 24 hours
        $this->assertSame('no_answer', $this->key($a));

        $this->travelTo($a->fresh()->next_call_at->copy()->addMinute());
        $this->act($a, 'no_response'); // no delay left: cancelled as unreachable
        $this->assertSame('cancelled', $this->key($a));
        $this->assertSame('unreachable', DB::table('status_reasons')->where('id', DB::table('order_events')->where('order_id', $a->id)->orderByDesc('id')->value('reason_id'))->value('system_key'));
    }

    public function test_a_return_time_outside_office_hours_waits_for_the_next_shift(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 21:50:00')); // office closes at 22:00
        $a = $this->web();
        app(OrderStateMachine::class)->transition($a, 'record_verified', null, 'rule');
        $this->actingAs($this->mahim)->post('/desk/next');
        $this->act($a, 'no_response');

        $this->assertSame('2026-10-06 09:00:00', $a->fresh()->next_call_at->toDateTimeString());
    }

    public function test_orders_nobody_took_are_given_to_the_least_busy_active_moderator(): void
    {
        $a = $this->web();
        $this->desk()->takeNext($this->mahim); // Mahim now holds one; Rima none
        $b = $this->web();

        Artisan::call('desk:tick');
        $this->assertNull($b->fresh()->moderator_id); // not 15 minutes yet

        $this->travel(16)->minutes();
        // Both were "seen" 16 minutes ago: nobody active, so it waits and managers are told.
        $manager = $this->owner();
        Artisan::call('desk:tick');
        $this->assertNull($b->fresh()->moderator_id);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $manager->id]);

        DB::table('users')->whereIn('id', [$this->mahim->id, $this->rima->id])->update(['last_seen_at' => now()]);
        $this->assertSame($a->id, $this->desk()->takeNext($this->mahim)->id); // his first order timed out meanwhile; he takes it again
        $c = $this->web(); // too new to auto-assign
        Artisan::call('desk:tick');

        $this->assertSame($this->rima->id, $b->fresh()->moderator_id); // Rima holds fewer
        $this->assertDatabaseHas('order_assignments', ['order_id' => $b->id, 'how' => 'auto']);
        $this->assertNull($c->fresh()->moderator_id);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->rima->id, 'subject_id' => $b->id]);
    }

    public function test_send_to_packing_books_the_courier_and_only_then_reaches_the_packers(): void
    {
        $a = $this->web();
        app(OrderStateMachine::class)->transition($a, 'record_verified', null, 'rule');
        $this->actingAs($this->mahim)->post('/desk/next');
        $this->act($a, 'confirm');

        // Not in the packing queue before a consignment exists.
        try {
            app(OrderStateMachine::class)->transition($a->fresh(), 'ready_for_packaging', null, 'system');
            $this->fail('No consignment yet');
        } catch (ValidationException) {
        }

        $this->act($a, 'send')->assertSessionHas('success'); // booking runs after the response (fake courier)
        $this->assertSame(1, DB::table('shipments')->where('order_id', $a->id)->count());

        $a->refresh();
        $this->assertSame('ready_for_packaging', $this->key($a));
        $this->assertNotNull($a->active_shipment_id);
        $this->assertNotNull($a->packing_sent_at);
        $this->assertSame('none', $a->booking_state);
        $this->get('/desk?tab=packing')->assertSee($a->order_no)->assertSee('Waiting for a packer');
    }

    public function test_chat_orders_belong_to_their_creator_without_timer_or_limit(): void
    {
        app(\App\Services\SettingsService::class)->set(['desk.active_limit' => 1]);
        $chat = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712000001', 'name' => 'Chat', 'address_line' => 'Road 2',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], $this->mahim);
        $this->desk()->armTimer($this->mahim->id);

        $this->assertSame($this->mahim->id, $chat->moderator_id);
        $this->assertNull($chat->fresh()->action_due_at);

        $web = $this->web();
        $this->assertSame($web->id, $this->desk()->takeNext($this->mahim)->id); // the chat order did not use up the limit
    }

    public function test_break_locks_the_screen_returns_uncalled_orders_and_is_recorded(): void
    {
        $a = $this->web();
        $this->actingAs($this->mahim)->post('/desk/next');
        $lunch = DB::table('status_reasons')->where('reason_type', 'break')->where('system_key', 'lunch')->value('id');

        $this->post('/breaks', ['reason_id' => $lunch]);
        $this->assertNotNull($this->mahim->fresh()->current_break_id);
        $this->assertNull($a->fresh()->moderator_id); // back in New, and no penalty
        $this->assertSame(0, DB::table('point_ledger')->count());
        $this->assertDatabaseHas('order_assignments', ['order_id' => $a->id, 'ended_reason' => 'break']);

        // Every page shows the break screen; nothing can be done until Start work.
        $this->get('/dashboard')->assertOk()->assertSee('On a break')->assertSee('Start work')->assertSee('ID #'.$this->mahim->id);
        $this->post('/desk/next')->assertSessionHas('error');
        $this->assertNull($a->fresh()->moderator_id);

        $this->travel(25)->minutes();
        $this->post('/breaks/end')->assertSessionHas('success');
        $this->assertNull($this->mahim->fresh()->current_break_id);
        $this->assertDatabaseHas('staff_breaks', ['user_id' => $this->mahim->id, 'minutes' => 25, 'auto_closed' => false]);
        $this->assertSame(25, app(BreakService::class)->countedMinutesToday($this->mahim->id));

        // Work away from the desk is recorded but not counted as break time.
        $shop = DB::table('status_reasons')->where('reason_type', 'break')->where('system_key', 'shop_visit')->value('id');
        $this->post('/breaks', ['reason_id' => $shop]);
        $this->travel(40)->minutes();
        $this->post('/breaks/end');
        $this->assertSame(25, app(BreakService::class)->countedMinutesToday($this->mahim->id));
    }

    public function test_break_left_open_is_closed_at_shift_end_and_flagged(): void
    {
        $manager = $this->owner();
        $prayer = DB::table('status_reasons')->where('reason_type', 'break')->where('system_key', 'prayer')->value('id');
        $this->travelTo(Carbon::parse('2026-10-05 16:10:00'));
        app(BreakService::class)->start($this->mahim, $prayer);

        $this->travelTo(Carbon::parse('2026-10-05 22:05:00')); // office closed at 22:00
        Artisan::call('desk:tick');

        $break = DB::table('staff_breaks')->first();
        $this->assertTrue((bool) $break->auto_closed);
        $this->assertSame('2026-10-05 22:00:00', $break->ended_at);
        $this->assertNull($this->mahim->fresh()->current_break_id);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $manager->id]);

        // Admin corrects it with a note; the day view shows it.
        $this->actingAs($manager)->post("/attendance/breaks/{$break->id}", ['ended_at' => '16:30', 'note' => 'Was at the bank for us'])->assertSessionHas('success');
        $this->assertSame(20, (int) DB::table('staff_breaks')->value('minutes'));
        $this->get('/attendance?date=2026-10-05')->assertOk()->assertSee('Mahim')->assertSee('Did not come back');
        $this->get('/attendance?month=2026-10')->assertOk()->assertSee('Mahim');
    }

    public function test_own_office_days_mark_a_friday_as_an_extra_day(): void
    {
        $manager = $this->owner();
        // Mahim works Monday only, 10:00 to 18:00.
        $this->actingAs($manager)->post("/users/{$this->mahim->id}/schedule", ['days' => [1 => ['on' => 1, 'start' => '10:00', 'end' => '18:00']]])->assertSessionHas('success');

        $this->travelTo(Carbon::parse('2026-10-09 11:00:00')); // Friday
        DB::table('users')->where('id', $this->mahim->id)->update(['last_seen_at' => null]);
        $this->actingAs($this->mahim->fresh())->get('/dashboard')->assertOk();

        $day = DB::table('work_days')->where('user_id', $this->mahim->id)->first();
        $this->assertSame('2026-10-09', substr($day->work_date, 0, 10));
        $this->assertTrue((bool) $day->is_extra);

        $this->actingAs($manager)->post("/attendance/days/{$day->id}/extra")->assertSessionHas('success');
        $this->assertNotNull(DB::table('work_days')->where('id', $day->id)->value('extra_approved_at'));
    }

    public function test_desk_page_stays_within_the_query_budget_and_admin_pages_render(): void
    {
        foreach (range(1, 4) as $i) {
            $this->web();
        }
        $this->actingAs($this->mahim);
        foreach (range(1, 3) as $i) {
            $this->post('/desk/next');
            $this->travel(4)->seconds();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/desk')->assertOk()->assertSee('Take next')->assertSee('Record OK, call next');
        // user + permissions (2), counts, list, waiting, reasons, order, customer, items, notes, duplicates
        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $this->get('/desk/control')->assertForbidden();
        $this->get('/kpi')->assertOk();

        $this->actingAs($this->owner());
        $this->get('/desk/control')->assertOk()->assertSee('Mahim')->assertSee('Waiting, nobody took');
        $this->get('/kpi?view=cohort')->assertOk()->assertSee('Mahim');
        $this->post('/kpi/targets', ['user_id' => $this->mahim->id, 'delivered_count' => 300, 'delivery_rate' => 85])->assertSessionHas('success');
        $this->get('/kpi?view=cohort')->assertSee('target 300 / month');
        $this->get('/packing')->assertOk()->assertSee('On duty today');
        $this->post('/packing/shift', ['user_ids' => [$this->rima->id]])->assertSessionHas('success');
        $this->assertDatabaseHas('packer_shifts', ['user_id' => $this->rima->id]);
    }
}
