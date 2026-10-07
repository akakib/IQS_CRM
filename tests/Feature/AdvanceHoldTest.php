<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Services\Orders\DeskService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\VerificationEngine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ConfirmationSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\PointsSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An order held for its advance is a call task: taken from the queue after
 * the New/Call orders, outside the active limit, called once, and released
 * by the advance (to booking, not a second call) or by an admin.
 */
class AdvanceHoldTest extends TestCase
{
    use RefreshDatabase;

    protected ProductVariant $variant;

    protected User $mod;

    private User $mod2;

    private User $admin;

    protected int $phone = 1712345679; // ends in 9: no Steadfast history (fake) = advance hold

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        foreach ([CatalogSeeder::class, CustomerSeeder::class, OrderConfigSeeder::class, ConfirmationSeeder::class, PointsSeeder::class, RoleSeeder::class] as $s) {
            (new $s)->run();
        }
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        $this->mod = User::factory()->create(['name' => 'Mod']);
        $this->mod2 = User::factory()->create(['name' => 'Mod2']);
        $this->admin = User::factory()->create(['name' => 'Boss']);
        $this->mod->roles()->attach(Role::firstWhere('system_key', 'moderator')->id);
        $this->mod2->roles()->attach(Role::firstWhere('system_key', 'moderator')->id);
        $this->admin->roles()->attach(Role::firstWhere('system_key', 'manager')->id);
        app(\App\Services\PermissionService::class)->bump();
        Product::factory()->withVariant(500, ['sku' => 'ALM'])->create(['name' => 'Almonds']);
        $this->variant = ProductVariant::firstWhere('sku', 'ALM');
    }

    /** A website order; last digit 9 = advance hold, 3 = good history (goes to Call). */
    protected function web(int $last = 9): Order
    {
        $phone = '0'.(intdiv($this->phone++, 10) * 10 + $last);
        $order = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => $phone, 'name' => 'Buyer', 'address_line' => 'Road 1', 'thana' => 'Mirpur',
            'zone_id' => DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], null, 'webhook');
        app(VerificationEngine::class)->run($order);

        return $order->fresh();
    }

    /** Take next (the 3-second double-click guard is cleared so a test can press it again at once). */
    protected function next(?User $as = null)
    {
        $as ??= $this->mod;
        \Illuminate\Support\Facades\Cache::forget('desk:take:'.$as->id); // the 3-second double-click guard

        return $this->actingAs($as)->post('/desk/next');
    }

    private function key(Order $o): string
    {
        return OrderStatus::map()[$o->fresh()->status_id]['key'];
    }

    private function bkash(): int
    {
        return (int) DB::table('payment_methods')->where('system_key', 'bkash')->value('id');
    }

    public function test_take_next_gives_call_orders_first_then_advance_holds_outside_the_limit(): void
    {
        $held = $this->web(9);
        $call = $this->web(3);
        $this->assertSame('hold', $this->key($held));
        $this->assertSame('record_verified', $this->key($call));

        $this->actingAs($this->mod);
        // The desk says both are waiting, and the pulse counts both (so the voice tells about them).
        $this->get('/desk')->assertSee('New order waiting')->assertSee('1 waiting for advance');
        $this->assertSame(2, $this->getJson('/desk/pulse')->json('waiting'));
        // 1. Call order first.
        $this->next()->assertSessionHasNoErrors();
        $this->assertSame($this->mod->id, $call->fresh()->moderator_id);
        $this->assertNull($held->fresh()->moderator_id);

        // 2. While that call is open, Take next brings them back to it (one order at a time).
        $this->next()->assertRedirect("/desk?tab=call&order={$call->id}");
        $this->assertNull($held->fresh()->moderator_id);
        // Call done: the advance hold is next, outside the active limit and without a timer.
        app(OrderStateMachine::class)->transition($call->fresh(), 'cancelled', $this->mod, 'user', (int) DB::table('status_reasons')->where('reason_type', 'cancel')->value('id'));
        $this->next()->assertRedirect("/desk?tab=call&order={$held->id}");
        $this->assertSame($this->mod->id, $held->fresh()->moderator_id);
        $this->assertNull($held->fresh()->action_due_at); // no timer on a hold
        $this->assertSame(0, app(DeskService::class)->activeCount($this->mod->id));

        // 3. One order at a time: that advance hold is not called yet, so Take next brings them back to it.
        $another = $this->web(9);
        $this->get('/desk')->assertSee('1 order waiting for its advance')->assertSee('Finish this order first');
        $this->next()->assertRedirect("/desk?tab=call&order={$held->id}");
        $this->assertNull($another->fresh()->moderator_id);
        // Called ("will pay by a date"): parked, the hand is free, the next one comes.
        $this->post("/orders/{$held->id}/advance-date", ['date' => today()->addDay()->toDateString()]);
        $this->next()->assertRedirect("/desk?tab=call&order={$another->id}");

        // 4. The parked cap: at most 5 advance holds per person, each called before the next.
        $this->post("/orders/{$another->id}/advance-date", ['date' => today()->addDay()->toDateString()]);
        for ($i = 0; $i < 3; $i++) {
            $o = $this->web(9);
            $this->next()->assertSessionHasNoErrors();
            $this->post("/orders/{$o->id}/advance-date", ['date' => today()->addDay()->toDateString()]);
        }
        $sixth = $this->web(9);
        $this->next()->assertSessionHasErrors('order');
        $this->assertNull($sixth->fresh()->moderator_id);

        // 5. The desk shows it in the Hold tab with the advance box; Resume is not offered.
        $this->get("/desk?tab=call&order={$held->id}")->assertOk()->assertSee('Advance needed')->assertSee('Customer paid: add payment')->assertDontSee('Resume, call next');
        // In Call (where calls are), not in On hold; the pulse tells about it like a new order.
        $this->get('/desk?tab=call')->assertSee($held->order_no);
        $this->get('/desk?tab=hold')->assertDontSee($held->order_no);
        $this->assertContains($held->id, collect($this->getJson('/desk/pulse')->json('mine'))->pluck('id')->all());
    }

    public function test_paid_during_the_call_goes_straight_to_booking_no_second_call(): void
    {
        $held = $this->web(9);
        $this->actingAs($this->mod)->post('/desk/next');
        $this->post("/orders/{$held->id}/payments", ['called' => 1, 'advance' => ['method_id' => $this->bkash(), 'amount' => (float) $held->advance_required, 'transaction_id' => 'CALLPAY1']])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('order_notes', ['order_id' => $held->id, 'note_type' => 'call']);
        $this->assertSame('ready_for_packaging', $this->key($held)); // confirmed and booked (fake courier)
        $this->assertSame((float) $held->fresh()->cod_amount, (float) DB::table('shipments')->where('order_id', $held->id)->value('cod_amount'));
    }

    public function test_paid_during_the_call_is_not_booked_by_itself_when_the_customer_has_another_open_order(): void
    {
        $first = $this->web(9);
        $phone = $first->ship_phone;
        $second = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => $phone, 'name' => 'Buyer', 'address_line' => 'Road 1', 'thana' => 'Mirpur',
            'zone_id' => DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], null, 'webhook');
        app(VerificationEngine::class)->run($second);
        $this->assertSame('hold', $this->key($second));
        DB::table('orders')->where('id', $second->id)->update(['moderator_id' => $this->mod->id]);
        $this->actingAs($this->mod)->post("/orders/{$second->id}/payments", ['called' => 1, 'advance' => ['method_id' => $this->bkash(), 'amount' => (float) $second->fresh()->advance_required, 'transaction_id' => 'DUP1']]);
        // Not booked: back in Call, the moderator decides (Confirm then asks "merge first?").
        $this->assertSame('record_verified', $this->key($second));
        $this->assertSame(0, DB::table('shipments')->where('order_id', $second->id)->count());
        $this->assertTrue(DB::table('order_notes')->where('order_id', $second->id)->where('body', 'like', '%'.$first->order_no.'%')->exists()); // the timeline says why
        $this->get("/desk?tab=call&order={$second->id}")->assertSee('Confirm anyway');
    }

    public function test_paid_later_without_a_call_goes_to_call_and_partial_stays_on_hold(): void
    {
        $held = $this->web(9);
        $need = (float) $held->advance_required;
        // Hotline adds part of it: still waiting, the box says how much more.
        $this->actingAs($this->admin)->post("/orders/{$held->id}/payments", ['advance' => ['method_id' => $this->bkash(), 'amount' => $need - 30, 'transaction_id' => 'PART1']]);
        $this->assertSame('hold', $this->key($held));
        $this->get("/orders/{$held->id}")->assertSee('৳30 more');
        // The rest: nobody called yet, so it goes to Call (nobody owns it: back in the queue).
        $this->post("/orders/{$held->id}/payments", ['advance' => ['method_id' => $this->bkash(), 'amount' => 30, 'transaction_id' => 'PART2']]);
        $this->assertSame('record_verified', $this->key($held));
        $this->assertNull($held->fresh()->moderator_id);
        $this->assertSame($held->id, app(DeskService::class)->waitingQuery()->value('id'));
    }

    public function test_will_pay_by_a_date_reminds_on_that_day_and_a_fake_advance_puts_it_back_on_hold(): void
    {
        $held = $this->web(9);
        $this->actingAs($this->mod)->post('/desk/next');
        $this->post("/orders/{$held->id}/advance-date", ['date' => today()->addDays(2)->toDateString()])->assertSessionHas('success');
        $this->assertDatabaseHas('order_notes', ['order_id' => $held->id, 'note_type' => 'call']);

        // Not yet: no notice. On the day: one notice, and only one however often it runs.
        $desk = app(DeskService::class);
        $this->assertSame(0, $desk->followUpHolds()['dated']);
        $this->travel(2)->days();
        $this->assertSame(1, $desk->followUpHolds()['dated']);
        $this->assertSame(0, $desk->followUpHolds()['dated']);
        $this->assertDatabaseHas('app_notifications', ['title' => "Hold date reached: {$held->order_no}"]);
        $this->assertSame('hold', $this->key($held)); // the date moved the clock: not cancelled at 2 days
        $this->travel(2)->days();
        $this->assertSame(1, $desk->followUpHolds()['cancelled']); // the day after the date passed with no money
        $this->assertSame('cancelled', $this->key($held));
        $this->actingAs($this->admin)->post("/orders/{$held->id}/transition", ['to' => 'new', 'reason_id' => DB::table('status_reasons')->where('reason_type', 'cancel')->value('id'), 'lock_version' => $held->fresh()->lock_version]);
        app(OrderStateMachine::class)->transition($held->fresh(), 'hold', $this->admin, 'user', app(DeskService::class)->advanceReasonId());
        $this->actingAs($this->mod);

        // The advance arrives: already called, so on to booking.
        $this->post("/orders/{$held->id}/payments", ['advance' => ['method_id' => $this->bkash(), 'amount' => (float) $held->advance_required, 'transaction_id' => 'FAKE1']]);
        $this->assertSame('ready_for_packaging', $this->key($held));

        // The TrxID was fake: booked already, so the courier COD update flow (not a hold).
        $pay = DB::table('order_payments')->where('transaction_id', 'FAKE1')->value('id');
        $this->actingAs($this->admin)->post('/payments/decide', ['ids' => [$pay], 'decision' => 'reject']);
        $this->assertSame('ready_for_packaging', $this->key($held));
        $this->assertNotNull(app(OrderService::class)->pendingCodUpdate($held->fresh()));

        // Same, but rejected before booking: back on the advance hold, with its moderator.
        $other = $this->web(9);
        $this->actingAs($this->mod)->post('/desk/next');
        \Illuminate\Support\Facades\Cache::put('fake-courier:fail', 'temporary'); // booking does not happen in this test
        $this->post("/orders/{$other->id}/payments", ['called' => 1, 'advance' => ['method_id' => $this->bkash(), 'amount' => (float) $other->advance_required, 'transaction_id' => 'FAKE2']]);
        $this->assertSame('confirmed', $this->key($other));
        $pay2 = DB::table('order_payments')->where('transaction_id', 'FAKE2')->value('id');
        $this->actingAs($this->admin)->post('/payments/decide', ['ids' => [$pay2], 'decision' => 'reject']);
        $this->assertSame('hold', $this->key($other));
        $this->assertSame($this->mod->id, $other->fresh()->moderator_id);
        $this->assertSame('none', $other->fresh()->booking_state);
    }

    public function test_no_advance_in_time_reminds_at_half_and_cancels_at_the_end(): void
    {
        app(\App\Services\SettingsService::class)->set(['advance.wait_days' => 2]);
        $held = $this->web(9);
        $this->actingAs($this->mod)->post('/desk/next');
        $desk = app(DeskService::class);

        $this->travel(23)->hours();
        $this->assertSame(['reminded' => 0, 'cancelled' => 0], array_intersect_key($desk->followUpHolds(), ['reminded' => 1, 'cancelled' => 1]));
        $this->travel(2)->hours(); // 25h: past half
        $r = $desk->followUpHolds();
        $this->assertSame(1, $r['reminded']);
        $this->assertSame(0, $r['cancelled']);
        $this->assertSame(0, $desk->followUpHolds()['reminded']); // once
        $this->assertDatabaseHas('app_notifications', ['title' => "Advance still missing: {$held->order_no}"]);

        $this->travel(24)->hours(); // 49h: past the wait
        $this->assertSame(1, $desk->followUpHolds()['cancelled']);
        $this->assertSame('cancelled', $this->key($held));
        $this->assertSame('no_advance', DB::table('status_reasons')->where('id', DB::table('order_events')->where('order_id', $held->id)->orderByDesc('id')->value('reason_id'))->value('system_key'));

        // Allowed without advance by an admin: never cancelled by the clock.
        $ok = $this->web(9);
        $this->actingAs($this->admin)->post("/orders/{$ok->id}/advance-waiver/decide", ['decision' => 'allow']);
        $this->assertSame('record_verified', $this->key($ok));
        $this->travel(3)->days();
        $this->assertSame(0, $desk->followUpHolds()['cancelled']);
    }

    public function test_auto_assign_hands_out_old_advance_holds_and_the_alert_counts_them(): void
    {
        $held = $this->web(9);
        $second = $this->web(9);
        $desk = app(DeskService::class);
        $this->travel(30)->minutes();
        DB::table('users')->whereIn('id', [$this->mod->id])->update(['last_seen_at' => now()]);
        $desk->autoAssign();
        $this->assertNull($held->fresh()->moderator_id); // too soon for a hold
        $this->travel(31)->minutes();
        DB::table('users')->whereIn('id', [$this->mod->id])->update(['last_seen_at' => now()]);
        $desk->autoAssign();
        $this->assertSame($this->mod->id, $held->fresh()->moderator_id);
        $this->assertNull($second->fresh()->moderator_id); // one at a time: the first is not called yet
        $this->assertDatabaseHas('app_notifications', ['title' => "Order {$held->order_no} was given to you (ask for the advance)"]);
    }

    public function test_chat_orders_on_advance_hold_are_released_to_their_moderator_to_confirm(): void
    {
        $this->actingAs($this->mod);
        $order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01899999999', 'name' => 'Chat', 'address_line' => 'Road 1',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ], $this->mod);
        app(VerificationEngine::class)->run($order);
        $this->assertSame('hold', $this->key($order));
        $this->post("/orders/{$order->id}/payments", ['called' => 1, 'advance' => ['method_id' => $this->bkash(), 'amount' => (float) $order->fresh()->advance_required, 'transaction_id' => 'CHAT1']]);
        $this->assertSame('record_verified', $this->key($order)); // not booked by itself: the moderator confirms in the chat
        $this->assertSame($this->mod->id, $order->fresh()->moderator_id);
    }
}
