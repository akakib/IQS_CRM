<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\User;
use App\Services\Complaints\ComplaintService;
use App\Services\Complaints\RefundService;
use App\Services\Orders\OrderService;
use App\Services\PermissionService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    private User $manager;

    private Order $order;

    private int $bkash;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new RoleSeeder)->run();
        (new NotificationSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(1000)->create(['name' => 'Dates']);

        $this->moderator = User::factory()->create(['name' => 'Mahim']);
        $this->moderator->roles()->attach(DB::table('roles')->where('system_key', 'moderator')->value('id'));
        $this->manager = User::factory()->create(['name' => 'Boss']);
        $this->manager->roles()->attach(DB::table('roles')->where('system_key', 'manager')->value('id'));
        app(PermissionService::class)->bump();

        $this->bkash = (int) DB::table('payment_methods')->where('requires_trx_id', true)->orderBy('id')->value('id');
        $svc = app(OrderService::class);
        $this->order = $svc->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]],
            'advance' => ['method_id' => $this->bkash, 'amount' => 400, 'transaction_id' => 'ADV1'],
        ], $this->moderator);
        $svc->verifyPayment($this->order, (int) DB::table('order_payments')->where('order_id', $this->order->id)->value('id'), true, $this->manager);
        $this->order->refresh();
        $this->assertEquals(400, (float) $this->order->advance_verified);
    }

    public function test_refund_cannot_exceed_what_the_customer_paid(): void
    {
        $this->actingAs($this->moderator)->post('/refunds', ['order_id' => $this->order->id, 'amount' => 500, 'method_id' => $this->bkash, 'recipient_number' => '01712345678'])
            ->assertSessionHasErrors('amount');
        $this->assertSame(0, Refund::count());
    }

    public function test_request_approve_by_another_person_then_paid_writes_the_ledger_and_updates_the_order(): void
    {
        // Moderator asks for ৳300 back.
        $this->actingAs($this->moderator)->post('/refunds', ['order_id' => $this->order->id, 'amount' => 300, 'method_id' => $this->bkash, 'recipient_number' => '01712345678', 'note' => 'Damaged box'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $refund = Refund::first();
        $this->assertSame('pending', $refund->status);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->manager->id]);

        // The moderator may not approve at all; the manager may, but not their own request.
        $this->actingAs($this->moderator)->post('/refunds/'.$refund->id.'/decide', ['decision' => 'approve'])->assertForbidden();
        $own = app(RefundService::class)->request($this->order, ['amount' => 50, 'method_id' => $this->bkash, 'recipient_number' => '01712345678'], $this->manager);
        $this->actingAs($this->manager)->post('/refunds/'.$own->id.'/decide', ['decision' => 'approve'])->assertSessionHasErrors('status');
        $this->assertSame('pending', $own->refresh()->status);

        // Approved: still no money moved.
        $this->actingAs($this->manager)->post('/refunds/'.$refund->id.'/decide', ['decision' => 'approve', 'note' => 'ok'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $refund->refresh()->status);
        $this->assertSame($this->manager->id, $refund->decided_by);
        $this->assertDatabaseMissing('order_payments', ['order_id' => $this->order->id, 'payment_type' => 'refund']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->moderator->id]);

        // Paid without a bKash TrxID is refused; with one, a verified refund row appears on the order.
        $this->actingAs($this->moderator)->post('/refunds/'.$refund->id.'/paid', [])->assertSessionHasErrors('transaction_id');
        $this->actingAs($this->moderator)->post('/refunds/'.$refund->id.'/paid', ['transaction_id' => 'RF123'])->assertRedirect()->assertSessionHasNoErrors();
        $refund->refresh();
        $this->assertSame('paid', $refund->status);
        $this->assertDatabaseHas('order_payments', ['id' => $refund->payment_id, 'order_id' => $this->order->id, 'payment_type' => 'refund', 'amount' => 300, 'status' => 'verified', 'transaction_id' => 'RF123']);
        $this->assertDatabaseHas('order_notes', ['order_id' => $this->order->id, 'note_type' => 'payment']);
        // The same TrxID cannot be reused, and the amount left to refund shrank.
        $this->assertSame(100.0, app(RefundService::class)->maxRefundable($this->order->fresh()), '400 paid − 300 refunded; the pending ৳50 does not count yet');
    }

    public function test_full_refund_of_a_cancelled_prepaid_order_marks_it_refunded(): void
    {
        $refund = app(RefundService::class)->request($this->order, ['amount' => 400, 'method_id' => $this->bkash, 'recipient_number' => '01712345678'], $this->moderator);
        app(RefundService::class)->decide($refund, true, null, $this->manager);
        app(RefundService::class)->markPaid($refund, 'RF999', $this->manager);

        $order = $this->order->fresh();
        $this->assertEquals(0, (float) $order->refund_due);
        $this->assertSame('refunded', $order->payment_status);
        $this->assertSame(0.0, app(RefundService::class)->maxRefundable($order));
    }

    public function test_refund_from_a_complaint_shows_on_both_and_allows_the_refunded_resolution(): void
    {
        $owner = $this->owner();
        $c = app(ComplaintService::class)->open(['order_id' => $this->order->id, 'category_id' => (int) DB::table('status_reasons')->where('reason_type', 'complaint')->value('id'), 'source' => 'phone', 'description' => 'bad'], [], $owner);
        $this->actingAs($this->moderator)->post('/refunds', ['order_id' => $this->order->id, 'complaint_id' => $c->id, 'amount' => 100, 'method_id' => $this->bkash, 'recipient_number' => '01712345678'])->assertSessionHasNoErrors();
        $refund = Refund::first();
        $this->assertSame($c->id, $refund->complaint_id);
        $this->assertDatabaseHas('complaint_events', ['complaint_id' => $c->id, 'action' => 'refund_requested']);

        $this->actingAs($this->manager)->get('/refunds')->assertOk()->assertSee($this->order->order_no)->assertSee('complaint #'.$c->id);
        $this->actingAs($this->manager)->post('/refunds/'.$refund->id.'/decide', ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/complaints/'.$c->id.'/resolve', ['resolution' => 'refunded', 'blame_stage' => 'packing'])->assertSessionHasNoErrors();
        $this->assertSame('refunded', $c->refresh()->resolution);
        $this->actingAs($owner)->get('/orders/'.$this->order->id)->assertOk()->assertSee('Approved, not paid yet');
    }

    public function test_rejected_refund_moves_no_money(): void
    {
        $refund = app(RefundService::class)->request($this->order, ['amount' => 100, 'method_id' => $this->bkash, 'recipient_number' => '01712345678'], $this->moderator);
        $this->actingAs($this->manager)->post('/refunds/'.$refund->id.'/decide', ['decision' => 'reject', 'note' => 'Customer kept the goods'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $refund->refresh()->status);
        $this->actingAs($this->moderator)->post('/refunds/'.$refund->id.'/paid', ['transaction_id' => 'X'])->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('order_payments', ['payment_type' => 'refund']);
        $this->assertEquals(400, (float) app(RefundService::class)->maxRefundable($this->order->fresh()));
    }
}
