<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderAmendmentTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private ProductVariant $dates;

    private ProductVariant $nuts;

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
        app(\App\Services\PermissionService::class)->bump();
        Product::factory()->withVariant(600, ['sku' => 'DATE', 'weight_g' => 500])->create(['name' => 'Dates']);
        Product::factory()->withVariant(400, ['sku' => 'NUTS', 'weight_g' => 250])->create(['name' => 'Nuts']);
        $this->dates = ProductVariant::firstWhere('sku', 'DATE');
        $this->nuts = ProductVariant::firstWhere('sku', 'NUTS');
    }

    private function order(): Order
    {
        return app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'zone_id' => DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->dates->id, 'qty' => 2]],
        ], $this->agent);
    }

    private function reason(string $key): int
    {
        return (int) DB::table('status_reasons')->where('reason_type', 'amendment')->where('system_key', $key)->value('id');
    }

    private function setStatus(Order $order, string $key, array $extra = []): void
    {
        $order->forceFill(['status_id' => OrderStatus::idFor($key)] + $extra)->save();
    }

    public function test_order_discount_is_kept_on_edit_can_be_changed_and_a_big_one_waits_for_a_manager(): void
    {
        $order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'zone_id' => DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->dates->id, 'qty' => 2]], 'order_discount' => 50,
        ], $this->agent);
        $this->assertSame('50.00', $order->fresh()->discount_total);

        // An edit that does not mention the discount keeps it (it used to be dropped).
        app(OrderEditor::class)->request($order, ['items' => [['variant_id' => $this->dates->id, 'qty' => 3]]], $this->reason('customer_request'), $this->agent, $order->fresh()->lock_version);
        $order->refresh();
        $this->assertSame('50.00', $order->discount_total);
        $this->assertSame(round(1800 - 50 + (float) $order->delivery_charge, 2), (float) $order->grand_total);

        // Only the discount changes: that is a change on its own.
        $result = app(OrderEditor::class)->request($order, ['items' => [['variant_id' => $this->dates->id, 'qty' => 3]], 'order_discount' => 100], $this->reason('customer_request'), $this->agent, $order->lock_version);
        $this->assertTrue($result['applied']);
        $this->assertSame('100.00', $order->fresh()->discount_total);
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'amendment']);

        // Above the limit (200): waits for a manager, nothing changes yet.
        $result = app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 3]], 'order_discount' => 500], $this->reason('customer_request'), $this->agent, $order->fresh()->lock_version);
        $this->assertFalse($result['applied']);
        $this->assertSame('100.00', $order->fresh()->discount_total);
    }

    public function test_a_website_order_keeps_its_sold_delivery_charge_until_the_delivery_area_changes(): void
    {
        $zone = fn (string $key) => DB::table('delivery_zones')->where('system_key', $key)->value('id');
        $order = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1', 'zone_id' => $zone('inside_dhaka'),
            'items' => [['variant_id' => $this->dates->id, 'qty' => 2]],
        ], null, 'webhook');
        $order->forceFill(['delivery_charge' => 33])->save(); // as sold on the website

        // Same area: the sold charge stays.
        app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 3]]], $this->reason('customer_request'), $this->agent, $order->fresh()->lock_version);
        $this->assertSame('33.00', $order->fresh()->delivery_charge);

        // Another area: the delivery rules decide.
        $expected = app(\App\Services\Orders\DeliveryCharges::class)->for($zone('outside_dhaka'), (int) $order->fresh()->total_weight_g, 1800.0);
        app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 3]], 'zone_id' => $zone('outside_dhaka')], $this->reason('customer_request'), $this->agent, $order->fresh()->lock_version);
        $this->assertSame(number_format($expected, 2, '.', ''), $order->fresh()->delivery_charge);
    }

    public function test_a_save_that_comes_back_with_an_error_keeps_the_lines_as_left(): void
    {
        $order = $this->order();
        $this->actingAs($this->agent)->from("/orders/{$order->id}/edit")->post("/orders/{$order->id}/amend", [
            'lock_version' => $order->lock_version,
            'items' => [['variant_id' => $this->dates->id, 'qty' => 2], ['variant_id' => $this->nuts->id, 'qty' => 4]],
            'ship_name' => 'Karim', 'ship_phone' => '01712345678', 'ship_address' => 'Road 1',
        ])->assertSessionHasErrors('reason_id');

        $this->get("/orders/{$order->id}/edit")->assertOk()->assertSee('NUTS');
    }

    public function test_free_edit_applies_now_with_version_note_and_new_totals(): void
    {
        $order = $this->order();
        $this->dates->prices()->update(['regular_price' => 999]); // list price changed after the sale

        $result = app(OrderEditor::class)->request($order, ['items' => [
            ['variant_id' => $this->dates->id, 'qty' => 3],
            ['variant_id' => $this->nuts->id, 'qty' => 1],
        ]], $this->reason('customer_request'), $this->agent, $order->lock_version);

        $this->assertTrue($result['applied']);
        $order->refresh();
        $this->assertSame(2, $order->current_version);
        $this->assertSame('600.00', $order->items->firstWhere('variant_id', $this->dates->id)->unit_price); // kept sold price
        $this->assertSame('2200.00', $order->subtotal); // 3×600 + 400
        $this->assertDatabaseHas('order_versions', ['order_id' => $order->id, 'version_no' => 2]);
        $note = DB::table('order_notes')->where('order_id', $order->id)->where('note_type', 'amendment')->value('body');
        $this->assertStringContainsString('Dates ×2 → ×3', $note);
        $this->assertStringContainsString('added Nuts ×1', $note);
        $this->assertStringContainsString('reason: Customer request', $note);
    }

    public function test_change_after_booking_waits_for_a_manager_then_applies(): void
    {
        $order = $this->order();
        $this->setStatus($order, 'ready_for_packaging');

        $result = app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 1]]],
            $this->reason('customer_request'), $this->agent, $order->fresh()->lock_version);

        $this->assertFalse($result['applied']);
        $this->assertSame(1, $order->fresh()->current_version);
        $this->assertDatabaseHas('order_amendments', ['id' => $result['amendment_id'], 'approval_status' => 'pending']);

        app(OrderEditor::class)->decide($order, $result['amendment_id'], true, $this->owner());
        $this->assertSame(2, $order->fresh()->current_version);
        $this->assertSame('1.000', $order->fresh()->items->first()->qty);
    }

    public function test_content_edit_after_packaging_marks_repack_and_alerts(): void
    {
        $order = $this->order();
        $this->setStatus($order, 'packed', ['packed_version' => 1]);

        app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 3]]],
            $this->reason('customer_request'), $this->owner(), $order->fresh()->lock_version);

        $order->refresh();
        $this->assertSame('repack', $order->packMark());
        $this->assertTrue($order->edited_after_pack);
        $this->assertDatabaseHas('order_amendments', ['order_id' => $order->id, 'edit_class' => 'content']);
    }

    public function test_address_only_edit_after_packaging_is_a_label_change(): void
    {
        $order = $this->order();
        $this->setStatus($order, 'packed', ['packed_version' => 1]);

        app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 2]], 'ship_address' => 'New road 9'],
            $this->reason('customer_request'), $this->owner(), $order->fresh()->lock_version);

        $order->refresh();
        $this->assertSame('edited', $order->packMark()); // box is right; only a new label
        $this->assertDatabaseHas('order_amendments', ['order_id' => $order->id, 'edit_class' => 'label']);
    }

    public function test_locked_status_and_no_change_are_refused(): void
    {
        $order = $this->order();
        try {
            app(OrderEditor::class)->request($order, ['items' => [['variant_id' => $this->dates->id, 'qty' => 2]]], $this->reason('customer_request'), $this->agent, $order->lock_version);
            $this->fail('No change should be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $this->setStatus($order, 'handed_over');
        $this->expectException(ValidationException::class);
        app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 1]]], $this->reason('customer_request'), $this->owner(), $order->fresh()->lock_version);
    }

    public function test_advance_above_new_total_creates_refund_due(): void
    {
        $order = $this->order();
        $bkash = DB::table('payment_methods')->where('system_key', 'bkash')->value('id');
        app(OrderService::class)->addPayment($order, ['method_id' => $bkash, 'amount' => (float) $order->grand_total, 'transaction_id' => 'T1'], $this->agent);
        app(OrderService::class)->verifyPayment($order, (int) DB::table('order_payments')->value('id'), true, $this->owner());
        $this->assertSame('fully_prepaid', $order->fresh()->payment_status);

        app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 1]]], $this->reason('customer_request'), $this->agent, $order->fresh()->lock_version);

        $order->refresh();
        $this->assertSame('0.00', $order->cod_amount);
        $this->assertGreaterThan(0, (float) $order->refund_due);
        $this->assertSame('refund_due', $order->payment_status);
    }

    public function test_edit_screen_and_post(): void
    {
        $order = $this->order();
        $this->actingAs($this->agent)->get("/orders/{$order->id}/edit")->assertOk()->assertSee('Why the change?');

        $this->post("/orders/{$order->id}/amend", [
            'lock_version' => $order->lock_version, 'reason_id' => $this->reason('entry_error'),
            'items' => [['variant_id' => $this->dates->id, 'qty' => 1]],
            'ship_name' => 'Karim', 'ship_phone' => '01712345678', 'ship_address' => 'Road 1',
        ])->assertRedirect("/orders/{$order->id}");

        $this->assertSame(2, $order->fresh()->current_version);
    }
}
