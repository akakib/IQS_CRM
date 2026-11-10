<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
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

class CourierWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'sf-token';

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
        config(['courier.steadfast.webhook_token' => self::TOKEN]);

        Product::factory()->withVariant(900)->create();
        $owner = $this->owner();
        $order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]],
        ], $owner);
        $sm = app(OrderStateMachine::class);
        $sm->transition($order, 'record_verified', null, 'rule');
        $sm->transition($order, 'confirmed', $owner);
        app(BookingService::class)->book([$order->id], $owner);
        $this->order = $order->fresh();
        $this->cn = DB::table('shipments')->where('order_id', $order->id)->value('consignment_id');
    }

    private function hook(array $payload, string $token = self::TOKEN)
    {
        return $this->withToken($token)->postJson('/webhooks/steadfast', $payload);
    }

    private function key(): string
    {
        return OrderStatus::map()[$this->order->fresh()->status_id]['key'];
    }

    public function test_delivered_webhook_finishes_the_order_and_counts_for_the_customer(): void
    {
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $this->cn, 'invoice' => $this->order->order_no,
            'status' => 'delivered', 'cod_amount' => 1000, 'delivery_charge' => 60, 'tracking_message' => 'Delivered to customer'])
            ->assertOk()->assertJson(['status' => 'received']);

        $this->assertSame('delivered', $this->key());
        $events = DB::table('order_events')->where('order_id', $this->order->id)->where('source', 'webhook')->pluck('to_status_id')
            ->map(fn ($id) => OrderStatus::map()[$id]['key'])->all();
        $this->assertSame(['in_transit', 'delivered'], $events); // handover scan was skipped: noted, not stuck
        $this->assertSame(1, Customer::first()->delivered_count);
        $shipment = DB::table('shipments')->where('order_id', $this->order->id)->first();
        $this->assertEquals(60, (float) $shipment->delivery_charge);
        $this->assertNotNull($shipment->final_at);
        $this->assertNotNull(DB::table('courier_events')->value('processed_at'));
    }

    public function test_tracking_update_goes_to_the_timeline(): void
    {
        $this->hook(['notification_type' => 'tracking_update', 'consignment_id' => $this->cn, 'tracking_message' => 'Rider picked up the parcel']);

        $this->assertDatabaseHas('order_notes', ['order_id' => $this->order->id, 'note_type' => 'courier', 'body' => 'Courier: Rider picked up the parcel']);
        $this->assertSame('ready_for_packaging', $this->key());
    }

    public function test_hold_opens_a_delivery_issue_for_the_moderator(): void
    {
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => $this->cn, 'status' => 'hold']);

        $issue = DB::table('delivery_issues')->where('order_id', $this->order->id)->first();
        $this->assertSame('hold', $issue->issue_type);
        $this->assertSame($this->order->moderator_id, $issue->assigned_to);
        $this->assertNotNull($issue->sla_due_at);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->order->moderator_id, 'subject_type' => 'order', 'priority' => 'urgent']);
        $this->assertSame('ready_for_packaging', $this->key());
    }

    public function test_cancelled_is_a_return_with_a_reason_to_set(): void
    {
        // Before pickup it is not a return: the parcel is on our shelf, someone books it again or cancels.
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => $this->cn, 'status' => 'cancelled', 'tracking_message' => 'rejected']);
        $this->assertSame('ready_for_packaging', $this->key());
        $this->assertDatabaseHas('delivery_issues', ['order_id' => $this->order->id, 'issue_type' => 'cancel']);
        DB::table('delivery_issues')->delete();
        DB::table('courier_events')->delete();

        DB::table('orders')->where('id', $this->order->id)->update(['status_id' => OrderStatus::idFor('handed_over')]);
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => $this->cn, 'status' => 'cancelled']);

        $this->assertSame('returned', $this->key());
        $this->assertSame(1, Customer::first()->returned_count);
        $this->assertDatabaseHas('delivery_issues', ['order_id' => $this->order->id, 'issue_type' => 'cancel']);
    }

    public function test_bad_token_and_duplicates(): void
    {
        $payload = ['notification_type' => 'delivery_status', 'consignment_id' => $this->cn, 'status' => 'delivered'];
        $this->hook($payload, 'wrong')->assertStatus(401);
        $this->hook($payload)->assertOk();
        $this->hook($payload)->assertJson(['status' => 'duplicate']);
        $this->assertSame(1, DB::table('courier_events')->count());
    }

    public function test_resync_pulls_status_from_the_fake_courier(): void
    {
        $this->travel(3)->hours(); // fake journey reaches delivered
        Artisan::call('courier:resync');

        $this->assertSame('delivered', $this->key());
        $this->assertDatabaseHas('courier_events', ['notification_type' => 'resync']);
    }

    public function test_pending_and_in_review_leave_a_parcel_on_our_shelf_alone(): void
    {
        // Steadfast reports in_review, then pending, from the moment a parcel is created: the box is still with us.
        foreach (['in_review', 'pending'] as $status) {
            $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $this->cn, 'invoice' => $this->order->order_no, 'status' => $status])->assertOk();
            $this->assertSame('ready_for_packaging', $this->key());
        }
        $this->assertSame(0, DB::table('delivery_issues')->count());
        $this->assertSame(0, DB::table('courier_events')->whereNull('processed_at')->count());

        // After the handover scan the same words change nothing either; delivered or returned does.
        DB::table('orders')->where('id', $this->order->id)->update(['status_id' => OrderStatus::idFor('handed_over')]);
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $this->cn, 'invoice' => $this->order->order_no, 'status' => 'pending'])->assertOk();
        $this->assertSame('handed_over', $this->key());
    }

    public function test_a_closed_order_or_a_held_order_never_loses_the_courier_news(): void
    {
        // Held: delivered cannot be applied, so an issue is opened and the event is not retried forever.
        DB::table('orders')->where('id', $this->order->id)->update(['status_id' => OrderStatus::idFor('hold')]);
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => $this->cn, 'status' => 'delivered', 'cod_amount' => 900])->assertOk();
        $this->assertDatabaseHas('delivery_issues', ['order_id' => $this->order->id, 'issue_type' => 'other']);
        $this->assertNotNull(DB::table('courier_events')->value('processed_at'));

        // Cancelled, but the courier delivered it: written on the parcel, managers told.
        DB::table('courier_events')->delete();
        DB::table('orders')->where('id', $this->order->id)->update(['status_id' => OrderStatus::idFor('cancelled')]);
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => $this->cn, 'status' => 'delivered', 'cod_amount' => 900])->assertOk();
        $this->assertEquals(900, (float) DB::table('shipments')->where('consignment_id', $this->cn)->value('collected_amount'));
        $this->assertDatabaseHas('app_notifications', ['title' => $this->order->order_no.': courier says delivered']);
    }

    public function test_a_parcel_changed_at_the_courier_blocks_the_handover_until_checked(): void
    {
        $cod = (float) $this->order->cod_amount;
        // Same COD: nothing happens.
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $this->cn, 'status' => 'in_review', 'cod_amount' => $cod])->assertOk();
        $this->assertNull($this->order->fresh()->courier_mismatch_at);

        // Changed in the Steadfast panel: flagged, managers told.
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $this->cn, 'status' => 'pending', 'cod_amount' => $cod + 500])->assertOk();
        $this->assertNotNull($this->order->fresh()->courier_mismatch_at);
        $this->assertDatabaseHas('app_notifications', ['title' => $this->order->order_no.': courier COD differs']);

        // The handover refuses it until a manager presses Checked.
        $owner = \App\Models\User::whereHas('roles', fn ($q) => $q->where('system_key', 'owner'))->first();
        $session = DB::table('handover_sessions')->insertGetId(['courier' => 'steadfast', 'pickup_date' => today(), 'started_by' => $owner->id, 'started_at' => now()]);
        DB::table('orders')->where('id', $this->order->id)->update(['status_id' => OrderStatus::idFor('ready_for_pickup'), 'packed_version' => 1]);
        $label = DB::table('shipment_labels')->where('order_id', $this->order->id)->whereNull('voided_at')->value('barcode');
        $r = app(\App\Services\Packaging\ScanService::class)->handover($session, $label, $owner);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('different COD', $r['message']);

        $this->actingAs($owner)->post(route('orders.courier-mismatch.clear', $this->order))->assertSessionHas('success');
        $this->assertNull($this->order->fresh()->courier_mismatch_at);
        $this->get(route('orders.show', $this->order))->assertOk()->assertDontSee('The courier has a different COD');
        // The same amount again does not block; another new amount does.
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $this->cn, 'status' => 'pending', 'cod_amount' => $cod + 500, 'tracking_message' => 'again'])->assertOk();
        $this->assertNull($this->order->fresh()->courier_mismatch_at);
        $this->hook(['notification_type' => 'delivery_status', 'consignment_id' => (int) $this->cn, 'status' => 'pending', 'cod_amount' => $cod + 900])->assertOk();
        $this->assertNotNull($this->order->fresh()->courier_mismatch_at);
    }

    private function delivered(): array
    {
        return ['notification_type' => 'delivery_status', 'consignment_id' => (int) $this->cn, 'invoice' => $this->order->order_no,
            'status' => 'delivered', 'cod_amount' => 1000, 'delivery_charge' => 60, 'tracking_message' => 'Delivered'];
    }

    private function savedButNotApplied(array $payload): int
    {
        return DB::table('courier_events')->insertGetId(['courier' => 'steadfast', 'consignment_id' => (string) ($payload['consignment_id'] ?? ''),
            'notification_type' => $payload['notification_type'], 'payload' => json_encode($payload), 'idempotency_key' => sha1(json_encode($payload)),
            'created_at' => now()->subMinutes(5)]);
    }

    public function test_a_message_saved_but_never_applied_is_tried_again(): void
    {
        $id = $this->savedButNotApplied($this->delivered()); // the process stopped after saving it

        $this->assertSame(1, app(\App\Services\Courier\CourierUpdateProcessor::class)->retryPending());
        $this->assertSame('delivered', $this->key());
        $this->assertNotNull(DB::table('courier_events')->where('id', $id)->value('processed_at'));
    }

    public function test_the_same_message_sent_again_is_applied_when_the_first_copy_was_not(): void
    {
        $this->savedButNotApplied($this->delivered());

        $this->hook($this->delivered())->assertOk()->assertJson(['status' => 'received']);
        $this->assertSame('delivered', $this->key());
        $this->hook($this->delivered())->assertJson(['status' => 'duplicate']); // applied now: a third copy is refused
        $this->assertSame(1, DB::table('courier_events')->count());
    }

    public function test_a_message_for_an_unknown_parcel_is_tried_five_times_then_closed(): void
    {
        $id = $this->savedButNotApplied(['notification_type' => 'delivery_status', 'consignment_id' => 999999, 'status' => 'delivered']);
        $retry = app(\App\Services\Courier\CourierUpdateProcessor::class);

        $retry->retryPending();
        $row = DB::table('courier_events')->find($id);
        $this->assertNull($row->processed_at);
        $this->assertSame('No shipment for this consignment / invoice.', $row->error);

        foreach (range(1, 4) as $n) {
            $retry->retryPending();
        }
        $row = DB::table('courier_events')->find($id);
        $this->assertSame(5, (int) $row->attempts);
        $this->assertNotNull($row->processed_at);
        $this->assertStringStartsWith('Gave up after 5 tries', $row->error);
    }

    public function test_an_ad_event_left_queued_is_sent_by_the_retry(): void
    {
        $log = DB::table('tracking_event_logs')->insertGetId(['order_id' => $this->order->id, 'platform' => 'meta_capi', 'event_name' => 'Purchase',
            'event_id' => 'e1', 'event_time' => now(), 'value' => 100, 'status' => 'queued', 'created_at' => now()->subMinutes(15)]);

        $this->assertSame(1, app(\App\Services\Tracking\TrackingSender::class)->retryStuck());
        $this->assertSame('sent', DB::table('tracking_event_logs')->where('id', $log)->value('status')); // fake send off production
    }

    public function test_delivery_credit_goes_to_whoever_confirmed_on_every_report(): void
    {
        $confirmer = (int) $this->order->confirmed_by;
        $other = \App\Models\User::factory()->create();
        DB::table('orders')->where('id', $this->order->id)->update(['moderator_id' => $other->id]); // given to someone else later
        $this->hook($this->delivered());

        $sales = app(\App\Services\Reports\SalesReport::class);
        $day = today()->toDateString();
        $this->assertSame(1, $sales->count('delivered', $day, $day, $confirmer));
        $this->assertSame(0, $sales->count('delivered', $day, $day, $other->id));
    }

    public function test_a_partial_delivery_counts_as_delivered_and_its_returned_pieces_as_returned(): void
    {
        DB::table('orders')->where('id', $this->order->id)->update(['status_id' => OrderStatus::idFor('partial_delivered')]);
        DB::table('order_events')->insert(['order_id' => $this->order->id, 'to_status_id' => OrderStatus::idFor('partial_delivered'), 'source' => 'webhook', 'created_at' => now()]);
        $item = DB::table('order_items')->where('order_id', $this->order->id)->first();
        DB::table('return_items')->insert(['order_id' => $this->order->id, 'order_item_id' => $item->id, 'variant_id' => $item->variant_id, 'qty' => 1, 'condition' => 'good', 'created_at' => now()]);

        $this->actingAs($this->owner())->get('/returns-report')->assertOk()
            ->assertSeeInOrder(['Returned', '0', 'Return rate', '0%', 'Partly returned', '1'])
            ->assertSee($item->name_snapshot);
    }
}
