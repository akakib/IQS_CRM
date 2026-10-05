<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Courier\BookingService;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use App\Services\Packing\BatchService;
use App\Services\Telegram\TelegramService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackingTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    private User $packer;

    private ProductVariant $dates;

    private ProductVariant $nuts;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new RoleSeeder)->run();
        (new NotificationSeeder)->run();
        OrderStatus::forget();
        TelegramService::$sent = [];
        config(['services.telegram.shop_chat_id' => '-100123']);

        Product::factory()->withVariant(600, ['sku' => 'DATE', 'shelf_code' => 'B2'])->create(['name' => 'Dates']);
        Product::factory()->withVariant(400, ['sku' => 'NUTS', 'shelf_code' => 'A1'])->create(['name' => 'Nuts']);
        $this->dates = ProductVariant::firstWhere('sku', 'DATE');
        $this->nuts = ProductVariant::firstWhere('sku', 'NUTS');

        $this->desk = $this->owner(['name' => 'Desk']);
        $this->packer = User::factory()->create(['name' => 'Packer', 'telegram_user_id' => '555']);
        $this->packer->roles()->attach(DB::table('roles')->where('system_key', 'packaging')->value('id'));
        app(\App\Services\PermissionService::class)->bump();
    }

    private function booked(string $phone, array $items): Order
    {
        $order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => $phone, 'name' => 'Karim', 'address_line' => 'Road 1', 'items' => $items,
        ], $this->desk);
        $sm = app(OrderStateMachine::class);
        $sm->transition($order, 'record_verified', null, 'rule');
        $sm->transition($order, 'confirmed', $this->desk);
        app(BookingService::class)->book([$order->id], $this->desk);

        return $order->fresh();
    }

    private function key(Order $o): string
    {
        return OrderStatus::map()[$o->fresh()->status_id]['key'];
    }

    private function label(Order $o): string
    {
        return DB::table('shipment_labels')->where('order_id', $o->id)->whereNull('voided_at')->value('barcode');
    }

    /** Scan the label (claims the order), tick every item, press Packed. */
    private function packIt(Order $o, ?string $code = null): void
    {
        $this->postJson('/packing/scan', ['code' => $code ?? $this->label($o)])->assertJson(['ok' => true]);
        $this->postJson("/packing/orders/{$o->id}/pack", ['items' => DB::table('order_items')->where('order_id', $o->id)->pluck('id')->all()])->assertJson(['ok' => true]);
    }

    public function test_release_posts_one_pick_list_sorted_by_shelf_without_customer_details(): void
    {
        $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 2], ['variant_id' => $this->nuts->id, 'qty' => 1]]);
        $this->booked('01812345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);

        $this->actingAs($this->desk)->post('/packing/release')->assertRedirect();

        $batch = DB::table('batches')->first();
        $lines = app(BatchService::class)->pickList($batch->id);
        $this->assertSame(['A1', 'B2'], $lines->pluck('shelf_code')->all());
        $this->assertEquals(3, $lines->firstWhere('shelf_code', 'B2')->qty);

        $msg = TelegramService::$sent[0];
        $this->assertSame('-100123', $msg['chat_id']);
        $this->assertStringContainsString('[A1] Nuts', $msg['text']);
        $this->assertStringNotContainsString('01712345678', $msg['text']);
        $this->assertStringNotContainsString('Karim', $msg['text']);
    }

    public function test_scan_to_pack_then_edit_marks_red_and_repack_with_new_label_clears_it(): void
    {
        $order = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 2]]);
        $this->actingAs($this->packer);

        // Scan = mine, with a checklist. Not packed until every item is ticked.
        $this->postJson('/packing/scan', ['code' => $this->label($order)])->assertJson(['ok' => true, 'level' => 'ok'])->assertJsonPath('checklist.order_no', $order->order_no);
        $this->assertSame('ready_for_packaging', $this->key($order));
        $this->assertSame($this->packer->id, $order->fresh()->packer_id);
        $this->postJson("/packing/orders/{$order->id}/pack", ['items' => []])->assertStatus(422);
        $this->packIt($order);
        $this->assertSame('packed', $this->key($order));
        $this->assertNotNull($order->fresh()->packed_at);
        $this->postJson('/packing/scan', ['code' => $this->label($order)])->assertJson(['ok' => false, 'result' => 'duplicate']);

        // Content edit after packing (manager approves since status needs approval).
        app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 3]]],
            DB::table('status_reasons')->where('reason_type', 'amendment')->where('system_key', 'customer_request')->value('id'), $this->desk, $order->fresh()->lock_version);
        $this->assertSame('repack', $order->fresh()->packMark());

        $old = $order->order_no.'-1';
        $this->postJson('/packing/scan', ['code' => $old])->assertJson(['ok' => false, 'level' => 'red'])
            ->assertJsonFragment(['message' => 'Edited after packing: repack. Dates ×2 → ×3 Print and scan the NEW label.']);

        app(BookingService::class)->issueLabel($order->fresh(), $order->fresh()->active_shipment_id, $this->desk, 'repack');
        $this->postJson('/packing/scan', ['code' => $old])->assertJson(['ok' => false])->assertJsonFragment(['result' => 'blocked']); // voided now
        $this->postJson('/packing/scan', ['code' => $order->order_no.'-2'])->assertJson(['ok' => true, 'level' => 'edited'])->assertJsonPath('checklist.repack', true);
        $this->assertSame('repack', $order->fresh()->packMark()); // still red until the items are ticked again
        $this->packIt($order, $order->order_no.'-2');
        $this->assertSame('edited', $order->fresh()->packMark());
    }

    public function test_handover_catches_duplicates_lists_missing_and_writes_the_manifest(): void
    {
        $a = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $b = $this->booked('01812345678', [['variant_id' => $this->nuts->id, 'qty' => 1]]);
        $this->actingAs($this->packer);
        $this->packIt($a);
        $this->packIt($b);

        $this->post('/handover', ['rider_name' => 'Rafiq', 'rider_phone' => '01911111111']);
        $session = DB::table('handover_sessions')->value('id');

        $this->postJson("/handover/{$session}/scan", ['code' => $this->label($a)])->assertJson(['ok' => true]);
        $this->postJson("/handover/{$session}/scan", ['code' => $this->label($a)])->assertJson(['ok' => false, 'result' => 'duplicate']);
        $this->postJson("/handover/{$session}/scan", ['code' => 'NOPE-1'])->assertJson(['ok' => false, 'result' => 'unknown']);
        $this->assertSame('handed_over', $this->key($a));

        $this->post("/handover/{$session}/close")->assertRedirect("/handover/{$session}/manifest");
        $this->get("/handover/{$session}/manifest")->assertOk()->assertSee($a->order_no)->assertSee('NOT handed over')->assertSee($b->order_no);
        $this->assertSame(3, DB::table('scan_logs')->where('handover_session_id', $session)->count());
    }

    public function test_handover_by_hand_runs_the_same_checks_and_is_marked_manual(): void
    {
        $good = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $edited = $this->booked('01812345678', [['variant_id' => $this->nuts->id, 'qty' => 1]]);
        $this->actingAs($this->packer);
        $this->packIt($good);
        $this->packIt($edited);
        // Edited after packing: must be repacked, so it cannot leave, by scan or by hand.
        app(OrderEditor::class)->request($edited->fresh(), ['items' => [['variant_id' => $this->nuts->id, 'qty' => 2]]],
            DB::table('status_reasons')->where('reason_type', 'amendment')->where('system_key', 'customer_request')->value('id'), $this->desk, $edited->fresh()->lock_version);

        $this->post('/handover', ['rider_name' => 'Rafiq']);
        $session = DB::table('handover_sessions')->value('id');
        $this->get("/handover/{$session}?tab=manual")->assertOk()->assertSee('Manual')->assertSee($good->order_no);

        $this->post("/handover/{$session}/manual", ['order_ids' => [$good->id, $edited->id]])->assertSessionHas('success');

        $this->assertSame('handed_over', $this->key($good));
        $this->assertDatabaseHas('scan_logs', ['handover_session_id' => $session, 'order_id' => $good->id, 'result' => 'ok', 'manual' => true]);
        $this->assertNotSame('handed_over', $this->key($edited));
        $this->assertStringContainsString($edited->order_no, session('refused')[0]);
        $this->get("/handover/{$session}/manifest")->assertOk()->assertSee('ticked by hand');
    }

    public function test_cancelled_or_held_order_is_refused_at_packing(): void
    {
        $order = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        app(OrderStateMachine::class)->transition($order, 'cancelled', $this->desk, 'user',
            DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'customer_cancelled')->value('id'));

        $this->actingAs($this->packer)->postJson('/packing/scan', ['code' => $this->label($order)])
            ->assertJson(['ok' => false, 'level' => 'red']);
        $this->assertSame('cancelled', $this->key($order));
    }

    public function test_packer_reports_missing_item_admin_marks_out_of_stock_and_orders_hold(): void
    {
        $order = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $this->actingAs($this->desk)->post('/packing/release');
        $batch = DB::table('batches')->value('id');

        $this->actingAs($this->packer)->post('/packing/report', ['variant_id' => $this->dates->id, 'batch_id' => $batch])->assertSessionHas('success');
        $this->assertTrue((bool) $order->fresh()->stock_issue_flag);
        $this->postJson('/packing/scan', ['code' => $this->label($order)])->assertJson(['ok' => false]);
        $this->assertSame('ready_for_packaging', $this->key($order)); // packer cannot change availability

        $report = DB::table('stock_issue_reports')->value('id');
        $this->actingAs($this->packer)->post("/packing/issues/{$report}", ['decision' => 'out_of_stock'])->assertForbidden();
        $this->actingAs($this->desk)->post("/packing/issues/{$report}", ['decision' => 'out_of_stock'])->assertSessionHas('success');

        $this->assertSame('out_of_stock', $this->dates->fresh()->availability_status);
        $this->assertSame('hold', $this->key($order));
        $this->assertFalse((bool) $order->fresh()->stock_issue_flag);
    }

    public function test_back_in_stock_releases_pre_order_holds_first_come_first_served(): void
    {
        $this->dates->update(['availability_status' => 'backorder']);
        $order = app(OrderService::class)->create(['channel' => 'messenger', 'phone' => '01712345678', 'name' => 'K', 'address_line' => 'R',
            'items' => [['variant_id' => $this->dates->id, 'qty' => 1]]], $this->desk);
        $sm = app(OrderStateMachine::class);
        $sm->transition($order, 'record_verified', null, 'rule');
        $sm->transition($order, 'confirmed', $this->desk); // -> hold (stock arriving)
        $this->assertSame('hold', $this->key($order));

        $this->actingAs($this->desk)->post('/products/availability', ['ids' => [$this->dates->id], 'status' => 'in_stock']);

        $this->assertSame('confirmed', $this->key($order));
        $this->assertDatabaseHas('restock_releases', ['variant_id' => $this->dates->id]);
    }

    public function test_telegram_picked_button_counts_for_the_linked_packer(): void
    {
        config(['services.telegram.webhook_secret' => 'tg-secret']);
        $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $this->actingAs($this->desk)->post('/packing/release');
        $batch = DB::table('batches')->value('id');

        $this->postJson('/webhooks/telegram', ['callback_query' => ['id' => 'cb1', 'from' => ['id' => 555], 'data' => "picked:{$batch}"]],
            ['X-Telegram-Bot-Api-Secret-Token' => 'tg-secret'])->assertOk();
        $this->assertSame($this->packer->id, DB::table('batches')->value('picked_by'));

        $this->postJson('/webhooks/telegram', [], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertStatus(401);
    }

    public function test_mini_app_needs_valid_telegram_sign_in(): void
    {
        config(['services.telegram.bot_token' => '123:ABC']);
        $user = json_encode(['id' => 555, 'first_name' => 'Packer']);
        $fields = ['auth_date' => (string) time(), 'user' => $user];
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "{$k}={$v}", array_keys($fields), $fields));
        $hash = hash_hmac('sha256', $check, hash_hmac('sha256', '123:ABC', 'WebAppData', true));
        $initData = http_build_query($fields + ['hash' => $hash]);

        $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $this->actingAs($this->desk)->post('/packing/release');
        auth()->logout();
        $batch = DB::table('batches')->value('id');

        $this->postJson('/tg/app/data', ['batch' => $batch, 'init_data' => $initData])->assertOk()->assertJsonPath('name', 'Packer');
        $this->postJson('/tg/app/data', ['batch' => $batch, 'init_data' => $initData.'x'])->assertStatus(401);
        $this->postJson('/tg/app/report', ['batch' => $batch, 'variant_id' => $this->dates->id, 'init_data' => $initData])->assertOk();
        $this->assertDatabaseHas('stock_issue_reports', ['reported_by' => $this->packer->id]);
    }
}
