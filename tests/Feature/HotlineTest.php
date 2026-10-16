<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Courier\BookingService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HotlineTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Order $order;

    private string $cn;

    protected function setUp(): void
    {
        parent::setUp();
        // Issues go to the moderator only inside their shift: run at a working hour, not whenever the suite runs.
        $this->travelTo(now()->next('Sunday')->setTime(12, 0));
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new RoleSeeder)->run();
        (new NotificationSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(900)->create(['name' => 'Figs']);

        $this->agent = User::factory()->create(['name' => 'Mahim']);
        $this->agent->roles()->attach(DB::table('roles')->where('system_key', 'moderator')->value('id'));
        app(\App\Services\PermissionService::class)->bump();

        $order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]],
        ], $this->agent);
        $sm = app(OrderStateMachine::class);
        $sm->transition($order, 'record_verified', null, 'rule');
        $sm->transition($order, 'confirmed', $this->agent);
        app(BookingService::class)->book([$order->id], $this->owner(['name' => 'Desk']));
        $this->order = $order->fresh();
        $this->cn = DB::table('shipments')->where('order_id', $order->id)->value('consignment_id');
    }

    public function test_hotline_finds_by_cn_and_phone_and_shows_cod_moderator_items(): void
    {
        $this->actingAs($this->owner());

        $this->get('/hotline?q='.$this->cn)->assertOk()->assertSee($this->order->order_no)->assertSee('Figs')->assertSee('Mahim')->assertSee('Road 1')->assertSee('CN '.$this->cn);
        $this->get('/hotline?q=+8801712345678')->assertSee($this->order->order_no);
    }

    public function test_parcel_steps_and_the_tracking_link_on_parcel_status_and_the_order(): void
    {
        $link = 'https://steadfast.com.bd/tl/CEi5c8K1aseluYom0ucBa643dJ_oHZHj';
        DB::table('shipments')->where('order_id', $this->order->id)->update(['tracking_link' => $link, 'courier_status' => 'hold']);
        $this->actingAs($this->owner());

        $this->get('/hotline?q='.$this->cn)->assertOk()->assertSee('Parcel status')->assertSee('Copy tracking link')->assertSee($link, false)
            ->assertSeeInOrder(['Booked', 'With courier', 'Delivered'])->assertSee('On hold at the courier')->assertSee('Re-sync courier status');
        $this->get(route('orders.show', $this->order))->assertOk()->assertSee('Copy tracking link')->assertSee($link, false);

        // Returned: the last step says so; a link that is not https is never shown.
        DB::table('shipments')->where('order_id', $this->order->id)->update(['tracking_link' => 'javascript:alert(1)', 'courier_status' => 'cancelled']);
        $this->get(route('orders.show', $this->order))->assertSee('Returned')->assertDontSee('Copy tracking link')->assertDontSee('javascript:alert', false);
    }

    public function test_re_sync_is_for_booking_and_parcel_status_people_once_a_minute(): void
    {
        $nobody = User::factory()->create();
        $this->actingAs($nobody)->post(route('shipping.resync'))->assertForbidden();

        $this->actingAs($this->owner());
        $this->from('/issues')->post(route('shipping.resync'))->assertRedirect('/issues')->assertSessionHas('success');
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get(\App\Http\Controllers\ShippingController::RESYNC_AT));
        $this->from('/issues')->post(route('shipping.resync'))->assertSessionHas('success', 'Checked less than a minute ago. Try again in a minute.');
        $this->get('/issues')->assertSee('Last checked');
    }

    public function test_bigger_issue_goes_to_the_moderator_with_a_timer_and_escalates_when_late(): void
    {
        $this->actingAs($this->owner());
        $this->post("/hotline/{$this->order->id}/issue", ['verdict' => 'true', 'issue_type' => 'partial', 'rider_phone' => '01811111111', 'note' => 'Wants only one item'])
            ->assertSessionHas('success');

        $issue = DB::table('delivery_issues')->first();
        $this->assertSame($this->agent->id, $issue->assigned_to);
        $this->assertSame('01811111111', $issue->rider_phone);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->agent->id, 'priority' => 'urgent']);

        $this->actingAs($this->agent)->get('/issues')->assertOk()->assertSee('Wants only one item');
        // Search and filters: by order number, and Overdue (not yet).
        $no = DB::table('orders')->where('id', $issue->order_id)->value('order_no');
        $this->get('/issues?q='.$no)->assertOk()->assertSee('Wants only one item');
        $this->get('/issues?q=IQ99999')->assertOk()->assertDontSee('Wants only one item')->assertSee('No issue matches these filters.');
        $this->get('/issues?show=overdue')->assertOk()->assertDontSee('Wants only one item');

        $this->travel(2)->hours();
        Artisan::call('issues:escalate');
        $this->assertNotNull(DB::table('delivery_issues')->value('escalated_at'));
        Artisan::call('issues:escalate');
        $this->assertSame(1, DB::table('order_notes')->where('body', 'like', '%sent to managers%')->count()); // once
    }

    public function test_owner_closes_the_issue_and_urgent_highlight_ends(): void
    {
        $this->actingAs($this->owner())->post("/hotline/{$this->order->id}/issue", ['issue_type' => 'no_answer', 'verdict' => 'true']);
        $issue = DB::table('delivery_issues')->value('id');

        $this->actingAs($this->agent)->post("/issues/{$issue}/resolve", ['resolution' => 'delivered', 'note' => 'Called, received'])->assertSessionHas('success');

        $this->assertSame('delivered', DB::table('delivery_issues')->value('resolution'));
        $this->assertSame(0, DB::table('app_notifications')->where('subject_id', $this->order->id)->where('priority', 'urgent')->whereNull('acted_at')->count());

        $other = User::factory()->create();
        $other->roles()->attach(DB::table('roles')->where('system_key', 'moderator')->value('id'));
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($this->owner())->post("/hotline/{$this->order->id}/issue", ['issue_type' => 'address', 'verdict' => 'true']);
        $second = DB::table('delivery_issues')->whereNull('resolved_at')->value('id');
        $this->actingAs($other)->post("/issues/{$second}/resolve", ['resolution' => 'solved'])->assertForbidden();
    }

    public function test_small_issue_is_saved_on_the_timeline(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post("/hotline/{$this->order->id}/solved", ['note' => 'Customer coming down in 5 minutes', 'rider_phone' => '01811111111'])->assertSessionHasErrors('verdict'); // what the customer said is asked here too
        $this->post("/hotline/{$this->order->id}/solved", ['note' => 'Customer coming down in 5 minutes', 'rider_phone' => '01811111111', 'verdict' => 'true'])->assertSessionHas('success');

        $this->assertDatabaseHas('order_notes', ['order_id' => $this->order->id, 'note_type' => 'rider']);
        // The same rider call as from Communication: counted in the Riders report and as work.
        $this->assertDatabaseHas('rider_calls', ['order_id' => $this->order->id, 'consignment_id' => $this->cn, 'claim' => 'other', 'verdict' => 'true',
            'action' => 'solved', 'note' => 'Customer coming down in 5 minutes', 'rider_phone' => '01811111111', 'handled_by' => $owner->id]);

        // Bigger issue: a rider call sent on, with its delivery issue.
        $this->post("/hotline/{$this->order->id}/issue", ['issue_type' => 'address', 'note' => 'Lane closed', 'verdict' => 'false'])->assertSessionHas('success');
        $call = DB::table('rider_calls')->where('action', 'moderator')->first();
        $this->assertSame(['address', 'address'], [$call->claim, DB::table('delivery_issues')->where('id', $call->delivery_issue_id)->value('issue_type')]);
        $this->get(route('riders-report.index'))->assertOk()->assertSee('Address wrong or not found');
    }

    public function test_parcel_status_and_rider_calls_find_the_parcel_the_same_way(): void
    {
        $this->actingAs($this->owner());
        foreach ([$this->cn, $this->order->order_no, '+8801712345678'] as $q) {
            $this->get('/hotline?q='.urlencode($q))->assertSee($this->order->order_no);
            $this->getJson('/rider-calls/find?q='.urlencode($q))->assertJson(['found' => true, 'order_no' => $this->order->order_no]);
        }
    }

    public function test_an_issue_waits_for_no_one_who_is_off_work_and_costs_them_nothing(): void
    {
        (new \Database\Seeders\PointsSeeder)->run();
        // 3 AM: outside every shift. The issue goes to the managers by name, not to the person at home.
        $this->travelTo(today()->setTime(3, 0));
        $this->actingAs($this->owner())->post("/hotline/{$this->order->id}/issue", ['verdict' => 'true', 'issue_type' => 'partial', 'note' => 'Night call']);
        $this->assertNull(DB::table('delivery_issues')->value('assigned_to'));

        // An issue given while at work whose shift then ended: handed to managers, no minus point.
        DB::table('delivery_issues')->update(['assigned_to' => $this->agent->id, 'sla_due_at' => now()->subMinute()]);
        Artisan::call('issues:escalate');
        $this->assertNotNull(DB::table('delivery_issues')->value('escalated_at'));
        $this->assertSame(0.0, (float) DB::table('point_ledger')->where('user_id', $this->agent->id)->where('points', '<', 0)->sum('points'));
        $this->assertSame(1, DB::table('order_notes')->where('body', 'Issue still open and its person is off work: sent to managers.')->count());
    }
}
