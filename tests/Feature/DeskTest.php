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
        // The shop default is one order at a time; most tests here need a moderator holding several.
        app(\App\Services\SettingsService::class)->set(['desk.active_limit' => 5]);

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

    public function test_a_held_order_goes_back_to_where_it_was_held_never_to_an_earlier_step(): void
    {
        $machine = app(\App\Services\Orders\OrderStateMachine::class);
        $hold = \Illuminate\Support\Facades\DB::table('status_reasons')->where('reason_type', 'hold')->value('id');
        $this->actingAs($this->mahim);

        // Held during the call: the call comes back.
        $calling = $this->web();
        $this->desk()->assign($calling->id, $this->mahim->id, 'claimed');
        $machine->transition($calling, 'record_verified', $this->mahim);
        $machine->transition($calling->fresh(), 'hold', $this->mahim, 'user', $hold);
        $this->get("/desk?tab=hold&order={$calling->id}")->assertSee('Resume, call next');
        $this->assertEqualsCanonicalizing(['record_verified', 'confirmed', 'cancelled'], collect($machine->allowedTargets($calling->fresh(), $this->mahim))->pluck('key')->all());

        // Held after Confirmed: straight to booking, no second call.
        $confirmed = $this->web();
        $this->desk()->assign($confirmed->id, $this->mahim->id, 'claimed');
        $machine->transition($confirmed, 'record_verified', $this->mahim);
        $machine->transition($confirmed->fresh(), 'confirmed', $this->mahim);
        $machine->transition($confirmed->fresh(), 'hold', $this->mahim, 'user', $hold);
        $this->get("/desk?tab=hold&order={$confirmed->id}")->assertSee('Send to packaging')->assertDontSee('Resume, call next');
        $this->assertNotContains('record_verified', collect($machine->allowedTargets($confirmed->fresh(), $this->mahim))->pluck('key'));
        $this->act($confirmed, 'resume')->assertSessionHasErrors('status');
        $this->assertSame('hold', $this->key($confirmed));
        $this->act($confirmed, 'back_to_send')->assertSessionHas('success');
        $this->assertSame('ready_for_packaging', $this->key($confirmed)); // booked right away
    }

    public function test_clicking_a_new_order_notice_takes_the_next_order_or_says_who_has_it(): void
    {
        app(\App\Services\SettingsService::class)->set(['desk.active_limit' => 1]); // the shop default: one at a time
        $older = $this->web();
        $newer = $this->web();
        // Mahim clicks the notice of the newer one: he gets the oldest (no jumping the queue).
        $this->actingAs($this->mahim)->get("/desk/notice/{$newer->id}")->assertRedirect("/desk?tab=verify&order={$older->id}");
        $this->assertSame($this->mahim->id, $older->fresh()->moderator_id);
        // Clicking again while that one runs (its 10-minute timer started on Take next): back to it, nothing taken.
        $this->get("/desk/notice/{$newer->id}")->assertRedirect("/desk?tab=verify&order={$older->id}")
            ->assertSessionHas('success', "Your timer is running on {$older->order_no}. Finish it first, then press Take next.");
        $this->assertNull($newer->fresh()->moderator_id);
        // Rima clicks the same notice: she gets the newer one; Mahim clicking his own opens it.
        $this->actingAs($this->rima)->get("/desk/notice/{$newer->id}")->assertRedirect("/desk?tab=verify&order={$newer->id}");
        $this->actingAs($this->mahim)->get("/desk/notice/{$older->id}")->assertRedirect("/desk?tab=verify&order={$older->id}");
        $this->get("/desk/notice/{$newer->id}")->assertSessionHas('success', "{$newer->order_no} is already with Rima.");

        // Room for more (limit 3) but a 10-minute timer runs: the click goes back to that order, nothing is taken.
        app(\App\Services\SettingsService::class)->set(['desk.active_limit' => 3]);
        $third = $this->web();
        $this->assertNotNull($older->fresh()->action_due_at); // still running from Take next
        $this->get("/desk/notice/{$third->id}")->assertRedirect("/desk?tab=verify&order={$older->id}")
            ->assertSessionHas('success', "Your timer is running on {$older->order_no}. Finish it first, then press Take next.");
        $this->assertNull($third->fresh()->moderator_id);
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
        $this->post('/desk/next')->assertRedirect(route('desk.index', ['tab' => 'verify', 'order' => $a->id])); // holds 2 of 2: opens what is in hand
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
        $this->assertNull($b->fresh()->action_due_at); // no clock starts behind the moderator's back
        $this->actingAs($this->mahim)->get('/desk')->assertOk();
        $this->assertNotNull($b->fresh()->action_due_at); // it starts when the order is open in front of them
        $this->assertDatabaseHas('order_assignments', ['order_id' => $a->id, 'user_id' => $this->mahim->id, 'ended_reason' => 'timeout']);
        $this->assertSame(-1.0, (float) DB::table('point_ledger')->where('user_id', $this->mahim->id)->sum('points'));

        // Someone else pressing Take next gets the released order (oldest first).
        $this->assertSame($a->id, $this->desk()->takeNext($this->rima)->id);
    }

    public function test_time_up_is_enforced_on_the_next_visit_and_on_the_next_click_without_cron(): void
    {
        [$a, $b] = [$this->web(), $this->web()];
        $this->actingAs($this->mahim)->post('/desk/next');
        $this->travel(4)->seconds();
        $this->post('/desk/next');
        $this->assertNotNull($a->fresh()->action_due_at);

        // Clicking after the time is up does nothing for the order: it goes back and costs the point.
        $this->travel(11)->minutes();
        $this->act($a, 'verify')->assertSessionHas('error');
        $this->assertNull($a->fresh()->moderator_id);
        $this->assertSame('new', $this->key($a));
        $this->assertSame(-1.0, (float) DB::table('point_ledger')->where('user_id', $this->mahim->id)->sum('points'));

        // The second order gets its timer once it is open; just opening the desk after it runs out takes it back too.
        $this->get('/desk')->assertOk();
        $this->assertNotNull($b->fresh()->action_due_at);
        $this->travel(11)->minutes();
        $this->get('/desk')->assertOk()->assertSee('Time ran out on '.$b->order_no);
        $this->assertNull($b->fresh()->moderator_id);
    }

    public function test_extra_time_once_per_order_with_a_daily_limit_and_a_small_cost(): void
    {
        app(\App\Services\SettingsService::class)->set(['desk.extend_daily_limit' => 1]);
        [$a, $b] = [$this->web(), $this->web()];
        $this->actingAs($this->mahim)->post('/desk/next');
        $due = $a->fresh()->action_due_at;

        $this->travel(8)->minutes();
        $this->get('/desk')->assertOk()->assertSee('+5 min');
        $this->post("/desk/{$a->id}/extend")->assertSessionHas('success');
        $this->assertTrue($a->fresh()->action_due_at->equalTo($due->copy()->addMinutes(5)));
        $this->assertSame(-0.5, (float) DB::table('point_ledger')->where('user_id', $this->mahim->id)->sum('points'));

        $this->post("/desk/{$a->id}/extend")->assertSessionHasErrors('order'); // once per order

        // 12 minutes after taking it: still mine thanks to the extra time.
        $this->travel(4)->minutes();
        $this->act($a, 'verify')->assertSessionHas('success');
        $this->assertSame($this->mahim->id, $a->fresh()->moderator_id);

        // Daily limit of 1 is used up.
        $this->act($a, 'confirm');
        $this->travel(4)->seconds();
        $this->post('/desk/next');
        $this->post("/desk/{$b->id}/extend")->assertSessionHasErrors('order');
    }

    public function test_acting_in_time_stops_the_timer(): void
    {
        $a = $this->web();
        $this->actingAs($this->mahim)->post('/desk/next');
        $this->act($a, 'verify')->assertSessionHas('success');

        $this->assertSame('record_verified', $this->key($a));
        $this->assertNull($a->fresh()->action_due_at); // the action stopped the clock
        $this->get('/desk?tab=call')->assertOk();
        $this->assertNotNull($a->fresh()->action_due_at); // open again to call: a fresh 10 minutes
        $this->travel(5)->minutes();
        $this->act($a, 'confirm', ['note' => 'will take it'])->assertSessionHas('success');

        $this->assertSame('ready_for_packaging', $this->key($a)); // confirm books the courier right away
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

        // Pressing again before the return time does nothing: the tries are not burned.
        $this->act($a, 'no_response')->assertSessionHasErrors('order');
        $this->assertSame(1, $a->fresh()->no_response_count);
        $this->assertSame('no_answer', $this->key($a));

        // 30 minutes later it is back in the Call tab; opening it starts the timer.
        $this->travel(31)->minutes();
        Artisan::call('desk:tick');
        $this->assertNull($a->fresh()->action_due_at);
        $this->get('/desk?tab=call')->assertSee($a->order_no);
        $this->assertNotNull($a->fresh()->action_due_at);

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

    public function test_send_to_packaging_books_the_courier_and_only_then_reaches_the_packers(): void
    {
        $a = $this->web();
        app(OrderStateMachine::class)->transition($a, 'record_verified', null, 'rule');
        $this->actingAs($this->mahim)->post('/desk/next');
        // Confirm books the courier right away. Here the courier is down for the first try.
        \Illuminate\Support\Facades\Cache::put('fake-courier:fail', 'temporary');
        $this->act($a, 'confirm')->assertSessionHas('success');
        $a->refresh();
        $this->assertSame('confirmed', $this->key($a));
        $this->assertSame('queued', $a->booking_state);
        $this->assertSame(1, $a->booking_attempts);
        $this->assertNull($a->booking_claim);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($a->book_after)->between(now()->addSeconds(50), now()->addSeconds(70))); // next try in 1 minute

        // Not in the packaging queue before a consignment exists.
        try {
            app(OrderStateMachine::class)->transition($a->fresh(), 'ready_for_packaging', null, 'system');
            $this->fail('No consignment yet');
        } catch (ValidationException) {
        }
        $this->get('/desk')->assertDontSee('Booking failed'); // still trying: the tab shows only for a real failure

        // Not yet due: the pulse does not book. Due: the next pulse books it (no cron needed).
        $this->getJson('/desk/pulse')->assertOk();
        $this->assertSame(0, DB::table('shipments')->where('order_id', $a->id)->count());
        $this->travel(61)->seconds();
        $this->getJson('/desk/pulse')->assertOk();
        $this->assertSame(1, DB::table('shipments')->where('order_id', $a->id)->count());

        $a->refresh();
        $this->assertSame('ready_for_packaging', $this->key($a));
        $this->assertNotNull($a->active_shipment_id);
        $this->assertNotNull($a->packaging_sent_at);
        $this->assertSame('none', $a->booking_state);
        $this->get('/desk?tab=packaging')->assertSee($a->order_no)->assertSee('Waiting for a packer')->assertSee('No packer yet');
    }

    public function test_one_order_is_never_sent_twice_and_each_kind_of_failure_is_handled(): void
    {
        $desk = $this->desk();
        $machine = app(OrderStateMachine::class);
        $confirmed = function () use ($machine) {
            $o = $this->web();
            $machine->transition($o, 'record_verified', null, 'rule');
            $machine->transition($o->fresh(), 'confirmed', null, 'rule'); // its own booking runs only when the app terminates: the test drives it instead

            return $o->fresh();
        };

        // 1. Another request holds it: this one skips it, nothing is sent twice.
        $a = $confirmed();
        DB::table('orders')->where('id', $a->id)->update(['booking_claim' => 'other-request', 'booking_claimed_at' => now()]);
        $desk->runBookings([$a->id]);
        $this->assertSame(0, DB::table('shipments')->where('order_id', $a->id)->count());

        // 2. Its edit waits while the courier is being booked.
        $this->actingAs($this->mahim);
        try {
            app(\App\Services\Orders\OrderEditor::class)->request($a->fresh(), ['items' => [['variant_id' => $this->variant->id, 'qty' => 2]]],
                (int) DB::table('status_reasons')->where('reason_type', 'amendment')->value('id'), $this->mahim, $a->fresh()->lock_version);
            $this->fail('Edit during booking');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('being booked right now', collect($e->errors())->flatten()->first());
        }

        // 3. That request died half way. The courier does not have it: released and booked normally.
        $this->travel(6)->minutes();
        $desk->recoverStale();
        $this->assertNull($a->fresh()->booking_claim);
        $desk->runBookings([$a->id]);
        $this->assertSame(1, DB::table('shipments')->where('order_id', $a->id)->count());

        // 4. Died half way and the courier already has it: never booked again, a person checks.
        $b = $confirmed();
        app(\App\Services\Courier\FakeCourierDriver::class)->bookBulk([new \App\Services\Courier\Data\BookingRequest(invoice: $b->order_no, recipientName: 'x', recipientPhone: '01700000000', recipientAddress: 'x', codAmount: 1, note: null)]);
        DB::table('orders')->where('id', $b->id)->update(['booking_claim' => 'dead-request', 'booking_claimed_at' => now()->subMinutes(10)]);
        $desk->recoverStale();
        $this->assertSame('failed', $b->fresh()->booking_state);
        $this->assertStringContainsString('may already be at the courier', $b->fresh()->booking_error);
        $this->assertSame(0, DB::table('shipments')->where('order_id', $b->id)->count());

        // 5. The courier refused the parcel's data: Booking failed at once, no pointless retries.
        $c = $confirmed();
        \Illuminate\Support\Facades\Cache::put('fake-courier:fail', 'rejected');
        $desk->runBookings([$c->id]);
        $this->assertSame('failed', $c->fresh()->booking_state);
        $this->assertSame(1, $c->fresh()->booking_attempts);

        // 6. Keys refused: the order waits (not blamed), one alert however many orders.
        $boss = User::factory()->create();
        $boss->roles()->attach(\App\Models\Role::firstWhere('system_key', 'owner')?->id ?? $this->role([], [], 'Owner')->id);
        DB::table('roles')->where('id', DB::table('user_roles')->where('user_id', $boss->id)->value('role_id'))->update(['system_key' => 'owner']);
        $d = $confirmed();
        $e = $confirmed();
        foreach ([$d, $e] as $o) {
            \Illuminate\Support\Facades\Cache::put('fake-courier:fail', 'auth');
            $desk->runBookings([$o->id]);
            $this->assertSame('queued', $o->fresh()->booking_state);
            $this->assertSame(0, $o->fresh()->booking_attempts);
        }
        $this->assertSame(1, DB::table('app_notifications')->where('title', 'The courier refused the API keys')->count());

        // 7. Temporary failures: 1 minute, 5 minutes, then Booking failed.
        $f = $confirmed();
        foreach ([1 => 'queued', 2 => 'queued', 3 => 'failed'] as $try => $state) {
            \Illuminate\Support\Facades\Cache::put('fake-courier:fail', 'temporary');
            DB::table('orders')->where('id', $f->id)->update(['book_after' => null]);
            $desk->runBookings([$f->id]);
            $this->assertSame($state, $f->fresh()->booking_state, "try $try");
        }

        // 8. Held before the courier was booked: no longer waiting to be booked.
        $g = $confirmed();
        $machine->transition($g->fresh(), 'hold', $this->mahim, 'user', (int) DB::table('status_reasons')->where('reason_type', 'hold')->value('id'));
        $this->assertSame('none', $g->fresh()->booking_state);
    }

    public function test_booked_by_mistake_goes_back_to_call_and_never_gets_a_second_parcel(): void
    {
        $machine = app(OrderStateMachine::class);
        $booked = function () use ($machine) {
            $o = $this->web();
            $machine->transition($o, 'record_verified', null, 'rule');
            $this->desk()->assign($o->id, $this->mahim->id, 'claimed');
            $this->actingAs($this->mahim);
            $this->act($o, 'confirm');
            $this->assertSame('ready_for_packaging', $this->key($o));

            return $o->fresh();
        };

        // 1. Waiting for a packer: its own moderator takes it back.
        $a = $booked();
        $label = DB::table('shipment_labels')->where('order_id', $a->id)->whereNull('voided_at')->value('barcode');
        $this->get("/orders/{$a->id}")->assertSee('Booked by mistake');
        $cn = DB::table('shipments')->where('order_id', $a->id)->value('consignment_id');
        $this->post("/orders/{$a->id}/take-back", ['why' => 'Customer had not confirmed'])->assertSessionHas('success');
        $a->refresh();
        $this->assertSame('record_verified', $this->key($a));
        $this->assertNotNull($a->taken_back_at);
        $this->assertNull($a->unpack_needed_at);
        $this->assertSame(0, DB::table('shipment_labels')->where('order_id', $a->id)->whereNull('voided_at')->count());
        $this->assertDatabaseHas('app_notifications', ['title' => "Delete {$a->order_no} at the courier"]);
        $this->get("/orders/{$a->id}")->assertSee('Taken back (booked by mistake)');

        // The old label never scans.
        $packer = User::factory()->create();
        $scan = app(\App\Services\Packaging\ScanService::class)->open($label, $packer);
        $this->assertStringContainsString('Do not pack', json_encode($scan));

        // No second parcel until the first is deleted at the courier.
        $this->act($a, 'confirm')->assertSessionHasErrors('status');
        $this->assertSame(1, DB::table('shipments')->where('order_id', $a->id)->count());
        $this->post("/orders/{$a->id}/courier-cancelled")->assertSessionHas('success');
        $this->assertNull($a->fresh()->taken_back_at);
        $this->act($a, 'confirm')->assertSessionHas('success');
        $this->assertSame('ready_for_packaging', $this->key($a));
        $this->assertSame(1, DB::table('shipments')->where('order_id', $a->id)->where('is_active', true)->count()); // the new one; the deleted one is inactive

        // 2. A packer started it: the moderator cannot, a manager can, and the packer must unpack.
        $b = $booked();
        $bLabel = DB::table('shipment_labels')->where('order_id', $b->id)->whereNull('voided_at')->value('barcode');
        app(\App\Services\Packaging\ScanService::class)->open($bLabel, $packer);
        $this->assertSame($packer->id, (int) $b->fresh()->packer_id);
        $this->post("/orders/{$b->id}/take-back", ['why' => 'Customer had not confirmed'])->assertSessionHasErrors('order');
        $manager = User::factory()->create();
        $manager->roles()->attach($this->role(['orders.view' => 'all', 'orders.edit', 'orders.approve'], [], 'Manager')->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($manager)->post("/orders/{$b->id}/take-back", ['why' => 'Wrong customer confirmed'])->assertSessionHas('success');
        $b->refresh();
        $this->assertSame('record_verified', $this->key($b));
        $this->assertNotNull($b->unpack_needed_at);
        $this->assertSame($packer->id, (int) $b->unpack_packer_id);
        $this->assertNull($b->packer_id);
        $this->assertDatabaseHas('app_notifications', ['title' => "Stop: {$b->order_no} was taken back"]);
        app(DeskService::class)->markUnpacked($b, $packer);
        $this->assertNull($b->fresh()->unpack_needed_at);

        // 3. With the courier already: no taking back, only Cancel.
        $c = $booked();
        $machine->transition($c->fresh(), 'packed', null, 'system');
        $machine->transition($c->fresh(), 'ready_for_pickup', null, 'system');
        $machine->transition($c->fresh(), 'handed_over', null, 'system');
        try {
            app(DeskService::class)->takeBack($c->fresh(), $manager, 'Too late test');
            $this->fail('Handed over');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('courier already has this parcel', collect($e->errors())->flatten()->first());
        }

        // All orders finds an order by its CN (even a deleted older parcel), and only that order.
        $this->actingAs($manager)->get('/orders')->assertSee($b->order_no);
        $this->get('/orders?q='.$cn)->assertSee($a->order_no)->assertDontSee($b->order_no);
    }

    public function test_payments_page_checks_advances_and_a_cod_change_after_booking_goes_to_the_courier(): void
    {
        $machine = app(OrderStateMachine::class);
        $bkash = DB::table('payment_methods')->where('system_key', 'bkash')->value('id');
        $manager = User::factory()->create();
        $manager->roles()->attach($this->role(['orders.view' => 'all', 'orders.edit', 'payments.verify'], [], 'Accounts')->id);
        app(\App\Services\PermissionService::class)->bump();

        // A big advance (over ৳500) stops Confirm until it is checked.
        $a = $this->web();
        $machine->transition($a, 'record_verified', null, 'rule');
        $this->desk()->assign($a->id, $this->mahim->id, 'claimed');
        $this->actingAs($this->mahim)->post("/orders/{$a->id}/payments", ['advance' => ['method_id' => $bkash, 'amount' => 550, 'transaction_id' => 'BIG1']])->assertSessionHas('success');
        $this->act($a, 'confirm')->assertSessionHasErrors('status');
        $this->assertSame('record_verified', $this->key($a));

        // Held for the advance: the check releases it and it is booked with the lower COD.
        $hold = (int) DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'advance_wait')->value('id');
        $machine->transition($a->fresh(), 'hold', $this->mahim, 'user', $hold);
        $this->get('/payments')->assertForbidden(); // moderators do not check money
        $this->actingAs($manager)->get('/payments')->assertOk()->assertSee('BIG1')->assertSee($a->order_no);
        $pay = DB::table('order_payments')->where('transaction_id', 'BIG1')->value('id');
        $this->post('/payments/decide', ['ids' => [$pay], 'decision' => 'approve'])->assertSessionHas('success');
        $this->assertSame('ready_for_packaging', $this->key($a)); // the call was logged already: straight to booking, no second call
        $this->assertSame((float) $a->fresh()->cod_amount, (float) DB::table('shipments')->where('order_id', $a->id)->value('cod_amount'));

        // A small advance after booking lowers the COD at once: update it at the courier, new label.
        $this->actingAs($this->mahim)->post("/orders/{$a->id}/payments", ['advance' => ['method_id' => $bkash, 'amount' => 30, 'transaction_id' => 'SMALL1']]);
        $this->assertNotNull(app(OrderService::class)->pendingCodUpdate($a->fresh()));
        $this->assertDatabaseHas('app_notifications', ['title' => "Update the COD of {$a->order_no} at the courier"]);

        // Rejected (did not match the statement): COD goes back up, the courier must be told again.
        $before = (float) $a->fresh()->cod_amount;
        $small = DB::table('order_payments')->where('transaction_id', 'SMALL1')->value('id');
        $this->actingAs($manager)->post('/payments/decide', ['ids' => [$small], 'decision' => 'reject']);
        $this->assertSame('rejected', DB::table('order_payments')->where('id', $small)->value('status'));
        $this->assertSame($before + 30, (float) $a->fresh()->cod_amount);
        $this->get('/payments?status=rejected')->assertSee('SMALL1');
    }

    public function test_confirm_warns_when_the_same_customer_has_another_open_order(): void
    {
        $first = $this->web();
        $second = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '0'.($this->phone - 1), 'name' => 'Customer', 'address_line' => 'Road 1',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], null, 'webhook');
        $this->assertSame($first->customer_id, $second->customer_id);
        app(OrderStateMachine::class)->transition($second, 'record_verified', null, 'rule');
        $this->desk()->assign($second->id, $this->mahim->id, 'claimed');

        $this->actingAs($this->mahim)->get("/desk?tab=call&order={$second->id}")
            ->assertSee('Confirm anyway')->assertSee($first->order_no);
    }

    public function test_by_default_the_next_order_cannot_be_taken_before_the_current_one_is_finished(): void
    {
        $this->assertSame(1, config('settings')['desk.active_limit'][1]);
        app(\App\Services\SettingsService::class)->set(['desk.active_limit' => 1]);
        [$a, $b] = [$this->web(), $this->web()];

        $this->actingAs($this->mahim)->post('/desk/next');
        $this->travel(4)->seconds();
        $this->post('/desk/next')->assertRedirect(route('desk.index', ['tab' => 'verify', 'order' => $a->id]))
            ->assertSessionHas('success', 'You already have '.$a->order_no.': opened it. Finish it, then take the next.');
        $this->assertNull($b->fresh()->moderator_id);
        $this->get('/desk')->assertOk()->assertSee('Finish this order first');

        // Verified but not called yet: still the same unfinished order.
        $this->act($a, 'verify');
        $this->travel(4)->seconds();
        $this->post('/desk/next')->assertRedirect(route('desk.index', ['tab' => 'call', 'order' => $a->id]));

        // Called and confirmed: the next one can be taken.
        $this->act($a->fresh(), 'confirm');
        $this->travel(4)->seconds();
        $this->post('/desk/next');
        $this->assertSame($this->mahim->id, $b->fresh()->moderator_id);
    }

    public function test_while_one_timer_runs_other_waiting_orders_cannot_be_worked_on(): void
    {
        [$a, $b] = [$this->web(), $this->web()];
        $this->actingAs($this->mahim)->post('/desk/next'); // a: taken, clock running
        $this->travel(4)->seconds();
        $this->post('/desk/next');                          // b: taken, no clock (one at a time)
        $this->assertNotNull($a->fresh()->action_due_at);
        $this->assertNull($b->fresh()->action_due_at);

        // Looking at b does not move the clock, and b cannot be acted on.
        $this->get('/desk?tab=verify&order='.$b->id)->assertOk()->assertSee('Finish that one first');
        $this->assertNull($b->fresh()->action_due_at);
        $this->act($b, 'verify')->assertSessionHasErrors('order');
        $this->assertSame('new', $this->key($b));

        // Arriving at the desk with no tab chosen lands on the order whose clock is running.
        $this->get('/desk')->assertOk()->assertSee('+'.settings('desk.extend_minutes').' min', false);

        // Finishing a frees b: opening it starts its clock.
        $this->act($a, 'verify')->assertSessionHas('success');
        $this->act($a->fresh(), 'hold', ['reason_id' => DB::table('status_reasons')->where('reason_type', 'hold')->value('id')]);
        $this->get('/desk?tab=verify&order='.$b->id)->assertOk();
        $this->assertNotNull($b->fresh()->action_due_at);
    }

    public function test_an_order_never_opened_starts_its_timer_after_the_untouched_limit(): void
    {
        $a = $this->web();
        $this->desk()->assign($a->id, $this->mahim->id, 'auto'); // handed over automatically: nobody is looking at it
        $this->assertNull($a->fresh()->action_due_at);

        $this->travel(10)->minutes();
        Artisan::call('desk:tick');
        $this->assertNull($a->fresh()->action_due_at);

        $this->travel(6)->minutes(); // 16 minutes untouched (limit 15)
        Artisan::call('desk:tick');
        $this->assertNotNull($a->fresh()->action_due_at);
    }

    public function test_voice_pulse_reports_what_the_moderator_has_and_does_not_count_as_presence(): void
    {
        // The bell's counts ride along, so a page with the pulse makes one background request, not two.
        $this->actingAs($this->mahim)->getJson('/desk/pulse')->assertOk()->assertJsonStructure(['notifications' => ['unread', 'urgent']]);

        [$a, $b] = [$this->web(), $this->web()];
        $this->actingAs($this->mahim)->post('/desk/next');
        $this->mahim->forceFill(['last_seen_at' => now()->subMinutes(30)])->save();

        $this->getJson('/desk/pulse')->assertOk()
            ->assertJsonPath('on_break', false)
            ->assertJsonPath('mine.0.no', $a->order_no)
            ->assertJsonPath('timed.id', $a->id)
            ->assertJsonPath('waiting', 1)
            ->assertJsonPath('can_take', true);
        $this->assertTrue($this->mahim->fresh()->last_seen_at->lt(now()->subMinutes(29))); // an open tab is not "active"

        // Without cron, the pulse hands out an order nobody took (at most once a minute).
        $this->travel(16)->minutes();
        $this->get('/desk'); // seen at work
        \Illuminate\Support\Facades\Cache::forget('desk:auto-assign');
        $this->getJson('/desk/pulse')->assertOk();
        $this->assertNotNull($b->fresh()->moderator_id);
    }

    public function test_busy_people_and_the_owner_are_not_told_about_new_orders_and_the_owner_cannot_take(): void
    {
        app(\App\Services\SettingsService::class)->set(['desk.active_limit' => 1]);
        $a = $this->web();
        $this->desk()->takeNext($this->mahim); // Mahim's hands are full
        $owner = User::factory()->create(['name' => 'Boss']);
        $owner->roles()->attach(\App\Models\Role::create(['name' => 'Owner', 'system_key' => 'owner'])->id);
        app(\App\Services\PermissionService::class)->bump();

        $busy = $this->desk()->notFreeForNewOrders();
        $this->assertContains($this->mahim->id, $busy);
        $this->assertNotContains($this->rima->id, $busy);

        $this->assertTrue($owner->fresh()->isOwner());
        $this->assertContains($owner->id, $busy);
        $this->web();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->desk()->takeNext($owner->fresh());
    }

    private function manager(): User
    {
        $boss = User::factory()->create(['name' => 'Boss']);
        $boss->roles()->attach($this->role(['orders.view' => 'all', 'orders.edit', 'orders.reassign'], [], 'Manager')->id);
        app(\App\Services\PermissionService::class)->bump();

        return $boss;
    }

    public function test_order_activity_board_shows_each_stage_with_who_holds_it_and_loads_more(): void
    {
        [$a, $b] = [$this->web(), $this->web()];
        $this->desk()->takeNext($this->mahim); // a: Mahim is checking it
        $this->actingAs($this->manager());

        $this->get('/orders/activity')->assertOk()
            ->assertSee('New, nobody took')->assertSee('Verify')
            ->assertSee($b->order_no)->assertSee($a->order_no)->assertSee('Mahim')
            ->assertSee('Waiting for someone to take it')
            ->assertDontSee('Call again'); // no order there: the column is not shown
        $this->get('/orders/activity', ['X-Board' => '1'])->assertOk()->assertDontSee('<html', false)->assertSee($a->order_no);
        $this->get('/orders/activity?staff='.$this->mahim->id)->assertOk()->assertSee($a->order_no)->assertDontSee($b->order_no);
        $this->get('/orders/activity?column=waiting&page=2')->assertOk()->assertDontSee($b->order_no); // only 2 orders: page 2 is empty

        // Staff cannot open the board.
        $this->actingAs($this->mahim)->get('/orders/activity')->assertForbidden();
    }

    public function test_the_board_popup_shows_the_order_lets_the_admin_act_and_hand_it_over(): void
    {
        $a = $this->web();
        $this->desk()->takeNext($this->mahim);
        $boss = $this->manager();
        $this->actingAs($boss);

        $this->get("/desk?embed=1&order={$a->id}")->assertOk()->assertSee($a->order_no)->assertSee('Give to someone else')->assertSee('Rima')
            ->assertDontSee('Take next')
            ->assertSee('antialiased" x-data>', false); // the popup page has an Alpine root, so its Edit popup can open

        // An action from the popup comes back to the same order in the popup.
        $this->post("/desk/{$a->id}/act", ['action' => 'verify', 'lock_version' => $a->fresh()->lock_version, 'embed' => 1])
            ->assertRedirect(route('desk.index', ['embed' => 1, 'order' => $a->id]));
        $this->assertSame('record_verified', $this->key($a));

        // Hand it to Rima: written to the history with the admin's name.
        $reason = DB::table('status_reasons')->where('reason_type', 'reassign')->value('id');
        $this->from("/desk?embed=1&order={$a->id}")->post("/orders/{$a->id}/reassign", ['user_id' => $this->rima->id, 'reason_id' => $reason])->assertRedirect();
        $this->assertSame($this->rima->id, $a->fresh()->moderator_id);
        $this->assertDatabaseHas('order_notes', ['order_id' => $a->id, 'note_type' => 'assignment', 'user_id' => $boss->id]);
    }

    public function test_one_person_edits_at_a_time_others_wait_and_the_moderators_timer_is_held(): void
    {
        $a = $this->web();
        $this->desk()->takeNext($this->mahim); // Mahim's 10-minute timer runs
        $boss = $this->manager();
        $presence = app(\App\Services\Orders\OrderPresence::class);

        // Both have it open: each sees the other.
        $presence->checkIn($a->fresh(), $this->mahim, 'view');
        $seen = $presence->checkIn($a->fresh(), $boss, 'view');
        $this->assertSame('Mahim', $seen['others'][0]['name']);

        // The boss opens the edit form: Mahim sees who, and his actions are refused.
        $this->assertTrue($presence->checkIn($a->fresh(), $boss, 'edit')['editing']);
        $this->assertSame('Boss', $presence->checkIn($a->fresh(), $this->mahim, 'edit')['editor']['name']);
        $this->actingAs($this->mahim)->post("/desk/{$a->id}/act", ['action' => 'verify', 'lock_version' => $a->fresh()->lock_version])->assertSessionHasErrors('order');
        $this->assertSame('new', $this->key($a));

        // While the boss edits, Mahim's timer does not run down.
        $due = $a->fresh()->action_due_at;
        $this->travel(10)->seconds();
        $presence->checkIn($a->fresh(), $boss, 'edit');
        $this->assertTrue($a->fresh()->action_due_at->greaterThan($due));

        // The boss leaves: Mahim can work again.
        $presence->checkIn($a->fresh(), $boss, 'leave');
        $this->assertNull($presence->editorOtherThan($a->id, $this->mahim->id));
        $this->post("/desk/{$a->id}/act", ['action' => 'verify', 'lock_version' => $a->fresh()->lock_version])->assertSessionHas('success');

        // A screen that stops checking in (tab closed) stops counting after a short while.
        $presence->checkIn($a->fresh(), $boss, 'edit');
        $this->travel(\App\Services\Orders\OrderPresence::STALE + 1)->seconds();
        $this->assertNull($presence->editorOtherThan($a->id, $this->mahim->id));

        // A manager can take over from someone who is editing.
        $presence->checkIn($a->fresh(), $this->mahim, 'edit');
        $this->assertSame('Mahim', $presence->checkIn($a->fresh(), $boss, 'edit')['editor']['name']);
        $this->assertTrue($presence->checkIn($a->fresh(), $boss, 'edit', true)['editing']);
        $this->assertSame('Boss', $presence->editorOtherThan($a->id, $this->mahim->id)['name']);
    }

    public function test_control_room_numbers_open_exactly_those_orders_page_by_page(): void
    {
        $orders = collect(range(1, 17))->map(fn () => $this->web());
        $this->desk()->takeNext($this->mahim); // the oldest is now Mahim's
        $this->actingAs($this->manager());

        // 16 waiting, nobody took: page 1 has 15, page 2 has the last one.
        $first = $this->get('/desk/control/list?box=waiting')->assertOk()->assertSee('16 orders')->assertSee($orders[1]->order_no)
            ->assertDontSee($orders[0]->order_no);
        $this->get('/desk/control/list?box=waiting&page=2')->assertOk()->assertSee($orders[16]->order_no)->assertDontSee($orders[1]->order_no);

        // A person's number: what that person holds.
        $this->get('/desk/control/list?box=holding&user='.$this->mahim->id)->assertOk()->assertSee('1 order')->assertSee($orders[0]->order_no);

        // A stage: same as its count.
        $this->get('/desk/control/list?box=stage&status='.OrderStatus::idFor('new'))->assertOk()->assertSee('17 orders');
        $this->get('/desk/control/list?box=breaks&user='.$this->mahim->id)->assertOk()->assertSee('No break today.');
    }

    public function test_opening_an_order_that_is_no_longer_yours_says_so(): void
    {
        $a = $this->web();
        $this->desk()->assign($a->id, $this->rima->id, 'auto');
        $this->actingAs($this->mahim)->get('/desk?order='.$a->id)->assertOk()->assertSee($a->order_no.' is no longer yours', false);
    }

    public function test_chat_orders_belong_to_their_creator_without_timer_or_limit(): void
    {
        app(\App\Services\SettingsService::class)->set(['desk.active_limit' => 1]);
        $chat = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712000001', 'name' => 'Chat', 'address_line' => 'Road 2',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], $this->mahim);
        $this->desk()->armTimer($this->mahim->id, $chat->id);

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
        // + expired-timer check (2), extra-time count (1), the due-booking check (1), the Steadfast snapshot (1)
        // the advance-hold reason id (1: advance holds are counted in the Call tab), the advance holds waiting (1)
        // and the orders in hand (2: active + uncalled advance holds)
        $this->assertLessThanOrEqual(21, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $this->get('/desk/control')->assertForbidden();
        $this->get('/kpi')->assertOk();

        $this->actingAs($this->owner());
        $this->get('/desk/control')->assertOk()->assertSee('Mahim')->assertSee('Waiting, nobody took');
        $this->get('/kpi?view=cohort')->assertOk()->assertSee('Mahim');
        $this->post('/kpi/targets', ['user_id' => $this->mahim->id, 'delivered_count' => 300, 'delivery_rate' => 85])->assertSessionHas('success');
        $this->get('/kpi?view=cohort')->assertSee('target 300 / month');
        $this->get('/packaging')->assertOk()->assertSee('On duty today');
        $this->post('/packaging/shift', ['user_ids' => [$this->rima->id]])->assertSessionHas('success');
        $this->assertDatabaseHas('packer_shifts', ['user_id' => $this->rima->id]);
    }
}
