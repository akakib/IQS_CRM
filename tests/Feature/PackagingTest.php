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
use App\Services\Packaging\BatchService;
use App\Services\Telegram\TelegramService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PackagingTest extends TestCase
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
        $this->postJson('/packaging/scan', ['code' => $code ?? $this->label($o)])->assertJson(['ok' => true]);
        $this->postJson("/packaging/orders/{$o->id}/pack", ['items' => DB::table('order_items')->where('order_id', $o->id)->pluck('id')->all()])->assertJson(['ok' => true]);
    }

    public function test_release_posts_one_pick_list_sorted_by_shelf_without_customer_details(): void
    {
        $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 2], ['variant_id' => $this->nuts->id, 'qty' => 1]]);
        $this->booked('01812345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);

        $this->actingAs($this->desk)->post('/packaging/release')->assertRedirect();

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
        $this->postJson('/packaging/scan', ['code' => $this->label($order)])->assertJson(['ok' => true, 'level' => 'ok'])->assertJsonPath('checklist.order_no', $order->order_no);
        $this->assertSame('ready_for_packaging', $this->key($order));
        $this->assertSame($this->packer->id, $order->fresh()->packer_id);
        $this->postJson("/packaging/orders/{$order->id}/pack", ['items' => []])->assertStatus(422);
        $this->packIt($order);
        $this->assertSame('packed', $this->key($order));
        $this->assertNotNull($order->fresh()->packed_at);
        $this->postJson('/packaging/scan', ['code' => $this->label($order)])->assertJson(['ok' => false, 'result' => 'duplicate']);

        // Content edit after packaging (manager approves since status needs approval).
        app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 3]]],
            DB::table('status_reasons')->where('reason_type', 'amendment')->where('system_key', 'customer_request')->value('id'), $this->desk, $order->fresh()->lock_version);
        $this->assertSame('repack', $order->fresh()->packMark());

        $old = $order->order_no.'-1';
        // The edit itself issued the new label (unprinted), so the packer can print it without asking anyone.
        $this->assertDatabaseHas('shipment_labels', ['barcode' => $order->order_no.'-2', 'voided_at' => null, 'printed_at' => null]);
        $this->postJson('/packaging/scan', ['code' => $old])->assertJson(['ok' => false])->assertJsonFragment(['result' => 'blocked']); // voided now
        $this->post("/packaging/{$order->id}/label")->assertOk()->assertSee($order->order_no.'-2');
        $this->assertNotNull(DB::table('shipment_labels')->where('barcode', $order->order_no.'-2')->value('printed_at'));
        $this->postJson('/packaging/scan', ['code' => $order->order_no.'-2'])->assertJson(['ok' => true, 'level' => 'edited'])->assertJsonPath('checklist.repack', true);
        $this->assertSame('repack', $order->fresh()->packMark()); // still red until the items are ticked again
        $this->packIt($order, $order->order_no.'-2');
        $this->assertSame('edited', $order->fresh()->packMark());
    }

    public function test_a_cod_change_after_booking_blocks_handover_until_someone_confirms_it_was_updated_by_hand(): void
    {
        $order = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 2]]);
        $this->actingAs($this->packer);
        $this->packIt($order);
        $reason = DB::table('status_reasons')->where('reason_type', 'amendment')->where('system_key', 'customer_request')->value('id');

        // More items: the cash to collect goes up after the courier was booked.
        app(OrderEditor::class)->request($order->fresh(), ['items' => [['variant_id' => $this->dates->id, 'qty' => 3]]], $reason, $this->desk, $order->fresh()->lock_version);
        $this->assertNotNull(app(\App\Services\Orders\OrderService::class)->pendingCodUpdate($order->fresh()));
        $this->assertDatabaseHas('app_notifications', ['title' => 'Update the COD of '.$order->order_no.' at the courier']);
        $this->packIt($order->fresh(), $this->label($order->fresh())); // repacked with the new label

        $this->post('/handover', ['rider_name' => ''])->assertSessionHasErrors('rider_name'); // a handover names its rider
        $this->post('/handover', ['rider_name' => 'Rafiq']);
        $session = DB::table('handover_sessions')->value('id');
        $this->postJson("/handover/{$session}/scan", ['code' => $this->label($order->fresh())])
            ->assertJson(['ok' => false, 'result' => 'blocked', 'level' => 'cod'])->assertJsonFragment(['message' => 'COD changed to ৳1,800 but not updated at the courier yet. Keep the parcel; ask the moderator or admin to update it, then scan again.']);

        // The stop is in the order's history: when, where, why, who packed it. Scanned again: counted, not repeated; one notice.
        $this->postJson("/handover/{$session}/scan", ['code' => $this->label($order->fresh())]);
        $stops = DB::table('order_notes')->where('order_id', $order->id)->where('note_type', 'scan')->get();
        $this->assertCount(1, $stops);
        $this->assertSame($this->packer->id, (int) $stops[0]->user_id);
        $this->assertStringStartsWith('Stopped at handover: COD changed to ৳1,800', $stops[0]->body);
        $this->assertStringContainsString('Handover of '.now()->format('d M').', rider Rafiq', $stops[0]->body);
        $this->assertStringContainsString('Packed by '.$this->packer->name.' at ', $stops[0]->body);
        $this->assertStringEndsWith('Scanned 2 times', $stops[0]->body);
        $this->assertSame(1, DB::table('app_notifications')->where('title', $order->order_no.' stopped at handover')->distinct()->count('title'));
        $this->actingAs($this->desk)->get("/orders/{$order->id}")->assertSee('Stopped at handover');
        $this->actingAs($this->packer);

        // The packer cannot confirm it (no button, and the server refuses).
        $this->actingAs($this->packer)->post("/orders/{$order->id}/cod-updated")->assertForbidden();
        $this->assertNotNull(app(\App\Services\Orders\OrderService::class)->pendingCodUpdate($order->fresh()));

        // Someone changed it in the courier panel and says so: the parcel can go.
        $this->actingAs($this->desk)->get("/orders/{$order->id}")->assertSee('Update the COD at');
        $this->post("/orders/{$order->id}/cod-updated")->assertSessionHas('success');
        $this->assertNull(app(\App\Services\Orders\OrderService::class)->pendingCodUpdate($order->fresh()));
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'courier', 'user_id' => $this->desk->id]);
        $this->actingAs($this->packer)->postJson("/handover/{$session}/scan", ['code' => $this->label($order->fresh())])->assertJson(['ok' => true]);
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
        $this->get("/handover/{$session}/manifest")->assertOk()->assertSee($a->order_no)->assertSee('NOT handed over')->assertSee($b->order_no)->assertSee('Pickup sheet')->assertSee(route('handover.excel', $session), false)->assertSee('Received by rider')->assertDontSee('window.print(), 300');
        $this->get("/handover/{$session}/manifest?print=1")->assertSee('Print / PDF')->assertSee('window.print(), 300', false)
            ->assertDontSee('window.close()', false)->assertDontSee('as=pdf', false); // Back, not Close; one Print / PDF button
        $this->assertSame(3, DB::table('scan_logs')->where('handover_session_id', $session)->count());

        // Any time later, from the list: the handed parcels only, same columns as the orders export; contact masked for packers.
        $this->get('/handover')->assertOk()->assertSee(route('handover.print', $session))->assertSee(route('handover.excel', $session));
        $print = $this->get("/handover/{$session}/print")->assertOk()->assertSee('Rider: Rafiq 01911111111')->assertSee($a->order_no)->assertDontSee($b->order_no)->assertSee('Orders: <b>1</b>', false);
        $print->assertDontSee('01712345678');
        $excel = $this->get("/handover/{$session}/excel")->assertOk();
        $zip = new \ZipArchive;
        $zip->open($excel->baseResponse->getFile()->getPathname());
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString($a->order_no, $sheet);
        $this->assertStringNotContainsString($b->order_no, $sheet);
    }

    public function test_helpers_are_set_with_the_packers_and_the_packers_report_counts_per_head(): void
    {
        // The day's packer and two helpers (names only, no login).
        $this->actingAs($this->desk)->post('/packaging/shift', ['user_ids' => [$this->packer->id], 'helpers' => [$this->packer->id => 'Karim, Sohel, Karim']])->assertSessionHas('success');
        $this->assertDatabaseHas('packer_shifts', ['user_id' => $this->packer->id, 'helpers' => 'Karim, Sohel', 'helper_count' => 2]);
        $this->get('/packaging')->assertSee('Packer + Karim, Sohel');

        $this->actingAs($this->packer);
        foreach (['01712345678', '01812345678', '01912345678'] as $phone) {
            $this->packIt($this->booked($phone, [['variant_id' => $this->dates->id, 'qty' => 1]]));
        }
        $this->postJson('/packaging/scan', ['code' => 'NOPE-1']); // a scan error

        // 3 packed by 1 packer + 2 helpers on 1 day = 1 per head.
        $this->actingAs($this->desk)->get('/packers-report')->assertOk()
            ->assertSeeInOrder(['Packer', '3', '1', '2', '3', '1'])->assertSeeInOrder(['Scan errors', 'Packer']);
        // Worked: time on each box (a box left open counts at most 30 minutes).
        $ids = DB::table('orders')->where('packer_id', $this->packer->id)->orderBy('id')->pluck('id');
        DB::table('orders')->where('id', $ids[0])->update(['packaging_started_at' => now()->subMinutes(10), 'packed_at' => now()]);
        DB::table('orders')->where('id', $ids[1])->update(['packaging_started_at' => now()->subHours(5), 'packed_at' => now()->subHours(3)]);
        $this->get('/packers-report')->assertSeeInOrder(['Packer', '3', '40 min']);
        $this->actingAs($this->packer)->get('/packers-report')->assertForbidden();
    }

    public function test_handover_by_hand_runs_the_same_checks_and_is_marked_manual(): void
    {
        $good = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $edited = $this->booked('01812345678', [['variant_id' => $this->nuts->id, 'qty' => 1]]);
        $this->actingAs($this->packer);
        $this->packIt($good);
        $this->packIt($edited);
        // Edited after packaging: must be repacked, so it cannot leave, by scan or by hand.
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

    public function test_cancelled_or_held_order_is_refused_at_packaging(): void
    {
        $order = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $code = $this->label($order);
        app(OrderStateMachine::class)->transition($order, 'cancelled', $this->desk, 'user',
            DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'customer_cancelled')->value('id'));

        $this->actingAs($this->packer)->postJson('/packaging/scan', ['code' => $code])
            ->assertJson(['ok' => false, 'level' => 'red']);
        $this->assertSame('cancelled', $this->key($order));
    }

    public function test_a_parcel_cancelled_after_booking_is_deleted_at_the_courier_by_hand_and_confirmed(): void
    {
        $order = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        app(OrderStateMachine::class)->transition($order, 'cancelled', $this->desk, 'user',
            DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'customer_cancelled')->value('id'));
        $service = app(\App\Services\Orders\OrderService::class);

        // The label no longer scans, people are told, and the order says what to do.
        $this->assertNull(DB::table('shipment_labels')->where('order_id', $order->id)->whereNull('voided_at')->value('id'));
        $this->assertDatabaseHas('app_notifications', ['title' => 'Delete '.$order->order_no.' at the courier']);
        $this->assertNotNull($service->pendingCourierCancel($order->fresh()));
        $this->actingAs($this->desk)->get("/orders/{$order->id}")->assertSee('Delete this parcel at');

        // A packer cannot confirm; a manager can, and it is recorded.
        $this->actingAs($this->packer)->post("/orders/{$order->id}/courier-cancelled")->assertForbidden();
        $this->actingAs($this->desk)->post("/orders/{$order->id}/courier-cancelled")->assertSessionHas('success');
        $this->assertNull($service->pendingCourierCancel($order->fresh()));
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'courier', 'user_id' => $this->desk->id]);
    }

    public function test_packer_reports_missing_item_admin_marks_out_of_stock_and_orders_hold(): void
    {
        $order = $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $this->actingAs($this->desk)->post('/packaging/release');
        $batch = DB::table('batches')->value('id');

        $this->actingAs($this->packer)->post('/packaging/report', ['variant_id' => $this->dates->id, 'batch_id' => $batch])->assertSessionHas('success');
        $this->assertTrue((bool) $order->fresh()->stock_issue_flag);
        $this->postJson('/packaging/scan', ['code' => $this->label($order)])->assertJson(['ok' => false]);
        $this->assertSame('ready_for_packaging', $this->key($order)); // packer cannot change availability

        $report = DB::table('stock_issue_reports')->value('id');
        $this->actingAs($this->packer)->post("/packaging/issues/{$report}", ['decision' => 'out_of_stock'])->assertForbidden();
        $this->actingAs($this->desk)->post("/packaging/issues/{$report}", ['decision' => 'out_of_stock'])->assertSessionHas('success');

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

        $this->assertSame('ready_for_packaging', $this->key($order)); // confirmed again, so the courier is booked right away
        $this->assertDatabaseHas('restock_releases', ['variant_id' => $this->dates->id]);
    }

    public function test_stock_out_and_back_in_tells_everyone_and_the_dashboard_lists_what_is_out(): void
    {
        $type = DB::table('notification_types')->where('system_key', 'stock_changed')->value('id');
        $this->actingAs($this->desk)->post('/products/availability', ['ids' => [$this->dates->id], 'status' => 'out_of_stock']);
        // Everyone hears it, not the person who did it.
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->packer->id, 'type_id' => $type, 'title' => 'Out of stock: '.$this->dates->product->name]);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $this->desk->id, 'type_id' => $type]);

        // The owner sees one tile with the count (the list is on Stock status); a packer without the right sees nothing of it.
        $this->get('/dashboard')->assertOk()->assertSee('Out of stock')->assertSee(route('products.availability'), false)->assertDontSee('Back in stock');
        $this->actingAs($this->packer)->get('/dashboard')->assertOk()->assertDontSee('Back in stock')->assertSee('To pack')->assertSee('Returns to receive');
        // The person who puts stock back (Ridoy) gets the full list with Back in stock.
        $stock = User::factory()->create(['name' => 'Stock keeper']);
        $stock->roles()->attach($this->role(['products.availability', 'packaging.view'], [], 'Stock keeper')->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($stock)->get('/dashboard')->assertOk()->assertSee($this->dates->product->name)->assertSee('Back in stock');

        $this->actingAs($this->desk)->post('/products/availability', ['ids' => [$this->dates->id], 'status' => 'in_stock'])->assertSessionHas('success');
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->packer->id, 'type_id' => $type, 'title' => 'Back in stock: '.$this->dates->product->name]);
        $this->actingAs($stock)->get('/dashboard')->assertSee('Nothing is out of stock.');
    }

    public function test_back_in_stock_a_website_order_nobody_called_goes_to_call_a_chat_order_to_confirmed(): void
    {
        $sm = app(OrderStateMachine::class);
        $web = app(OrderService::class)->create(['channel' => 'web', 'phone' => '01712345671', 'name' => 'W', 'address_line' => 'R',
            'items' => [['variant_id' => $this->dates->id, 'qty' => 1]]], null, 'webhook');
        $chat = app(OrderService::class)->create(['channel' => 'messenger', 'phone' => '01712345672', 'name' => 'C', 'address_line' => 'R',
            'items' => [['variant_id' => $this->dates->id, 'qty' => 1]]], $this->desk);
        $sm->transition($chat, 'record_verified', null, 'rule');
        $this->actingAs($this->desk)->post('/products/availability', ['ids' => [$this->dates->id], 'status' => 'out_of_stock']);
        $this->assertSame(['hold', 'hold'], [$this->key($web), $this->key($chat)]); // held before anyone confirmed

        // Order management offers what ending the hold will do: the chat order goes on, the website order is called first.
        $this->get(route('desk.index', ['order' => $chat->id]))->assertSee('Send to packaging')->assertDontSee('Resume, call next');
        $this->get(route('desk.index', ['order' => $web->id]))->assertSee('Resume, call next');

        $this->post('/products/availability', ['ids' => [$this->dates->id], 'status' => 'in_stock']);
        $this->assertSame('record_verified', $this->key($web)); // never booked before a call
        $this->assertDatabaseHas('order_notes', ['order_id' => $web->id, 'body' => 'Hold → Record verified · Stock arrived: call the customer before it is booked.']);
        $this->assertSame('ready_for_packaging', $this->key($chat)); // confirmed in the chat: on to booking
    }

    public function test_telegram_picked_button_counts_for_the_linked_packer(): void
    {
        config(['services.telegram.webhook_secret' => 'tg-secret']);
        $this->booked('01712345678', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $this->actingAs($this->desk)->post('/packaging/release');
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
        $this->actingAs($this->desk)->post('/packaging/release');
        auth()->logout();
        $batch = DB::table('batches')->value('id');

        $this->postJson('/tg/app/data', ['batch' => $batch, 'init_data' => $initData])->assertOk()->assertJsonPath('name', 'Packer');
        $this->postJson('/tg/app/data', ['batch' => $batch, 'init_data' => $initData.'x'])->assertStatus(401);
        $this->postJson('/tg/app/report', ['batch' => $batch, 'variant_id' => $this->dates->id, 'init_data' => $initData])->assertOk();
        $this->assertDatabaseHas('stock_issue_reports', ['reported_by' => $this->packer->id]);
    }

    public function test_counted_stock_goes_down_when_packed_back_up_when_the_box_is_put_back_and_out_at_zero(): void
    {
        // Count Dates on the shelf: 3. Nuts are not counted, so they are not tracked.
        $this->actingAs($this->desk)->get('/products/stock')->assertOk()->assertSee('Stock count')->assertSee('Not counted');
        $this->postJson('/products/stock/'.$this->dates->id, ['qty' => 3])->assertOk()->assertJson(['qty' => 3]);
        $this->actingAs($this->packer)->postJson('/products/stock/'.$this->dates->id, ['qty' => 9])->assertForbidden(); // counting is for stock managers

        $a = $this->booked('01811111111', [['variant_id' => $this->dates->id, 'qty' => 2], ['variant_id' => $this->nuts->id, 'qty' => 1]]);
        $b = $this->booked('01822222222', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $this->assertSame(3.0, $this->dates->fresh()->stock_qty); // confirmed is not off the shelf yet

        $this->actingAs($this->packer);
        $this->packIt($a);
        $this->assertSame(1.0, $this->dates->fresh()->stock_qty);
        $this->assertNull($this->nuts->fresh()->stock_qty);

        // The last one packed: none left, so it goes Out of stock by itself.
        $this->packIt($b);
        $this->assertSame(0.0, $this->dates->fresh()->stock_qty);
        $this->assertSame('out_of_stock', $this->dates->fresh()->availability_status);

        // Cancelled after packing: the box is opened and the item put back, on the shelf again and back In stock.
        DB::table('orders')->where('id', $b->id)->update(['unpack_needed_at' => now(), 'status_id' => OrderStatus::idFor('cancelled')]);
        app(\App\Services\Orders\DeskService::class)->markUnpacked($b->fresh(), $this->packer);
        $this->assertSame(1.0, $this->dates->fresh()->stock_qty);
        $this->assertSame('in_stock', $this->dates->fresh()->availability_status);
        app(\App\Services\Orders\DeskService::class)->markUnpacked($b->fresh(), $this->packer); // twice changes nothing
        $this->assertSame(1.0, $this->dates->fresh()->stock_qty);

        // Every change is listed with why and which order.
        $this->actingAs($this->desk)->getJson('/products/stock/'.$this->dates->id.'/history')->assertOk()
            ->assertJsonPath('rows.0.why', 'Box put back')->assertJsonPath('rows.0.order', $b->order_no)
            ->assertJsonPath('rows.3.why', 'Counted')->assertJsonPath('rows.3.after', 3);
    }

    /** The courier sent it back: status Returned, as the webhook leaves it. */
    private function returned(Order $o, string $when = 'now'): void
    {
        DB::table('orders')->where('id', $o->id)->update(['status_id' => OrderStatus::idFor('returned')]);
        DB::table('order_events')->insert(['order_id' => $o->id, 'to_status_id' => OrderStatus::idFor('returned'), 'source' => 'webhook', 'created_at' => \Illuminate\Support\Carbon::parse($when)]);
    }

    public function test_a_returned_parcel_is_received_item_by_item_and_good_items_go_back_on_the_shelf(): void
    {
        $this->actingAs($this->desk)->postJson('/products/stock/'.$this->dates->id, ['qty' => 3]);
        $a = $this->booked('01811111111', [['variant_id' => $this->dates->id, 'qty' => 2], ['variant_id' => $this->nuts->id, 'qty' => 1]]);
        $this->actingAs($this->packer);
        $this->packIt($a);
        $this->assertSame(1.0, $this->dates->fresh()->stock_qty);
        $this->returned($a);

        // To receive lists it; a scan of its label finds it with its items.
        $this->get('/returns')->assertOk()->assertSee($a->order_no);
        $items = DB::table('order_items')->where('order_id', $a->id)->pluck('id', 'variant_id');
        $this->postJson('/returns/find', ['code' => $this->label($a)])->assertJson(['ok' => true])->assertJsonCount(2, 'order.items');

        // Every item must be marked; then Good goes back on the shelf, Damaged does not, and the managers hear of it.
        $this->postJson("/returns/{$a->id}", ['items' => [$items[$this->dates->id] => 'good']])->assertStatus(422);
        // Piece by piece: of the 2 dates, 1 good and 1 damaged; the numbers must add up.
        $this->postJson("/returns/{$a->id}", ['items' => [$items[$this->dates->id] => ['good' => 1], $items[$this->nuts->id] => 'damaged']])->assertStatus(422);
        $this->postJson("/returns/{$a->id}", ['items' => [$items[$this->dates->id] => ['good' => 1, 'kept' => 1], $items[$this->nuts->id] => 'damaged']])->assertStatus(422); // not a partial
        $this->postJson("/returns/{$a->id}", ['items' => [$items[$this->dates->id] => ['good' => 1, 'damaged' => 1], $items[$this->nuts->id] => 'damaged']])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(2.0, $this->dates->fresh()->stock_qty);
        $this->assertNotNull($a->fresh()->return_received_at);
        $this->assertDatabaseHas('return_items', ['order_id' => $a->id, 'variant_id' => $this->nuts->id, 'condition' => 'damaged']);
        $this->assertDatabaseHas('order_notes', ['order_id' => $a->id, 'body' => 'Return received at the shop by Packer: 1 good, 2 damaged, 0 not in the parcel.']);
        $this->assertSame(1.0, (float) DB::table('return_items')->where('order_id', $a->id)->where('variant_id', $this->dates->id)->where('condition', 'damaged')->value('qty'));

        // Twice is refused; an order that was not returned is not taken in.
        $this->postJson('/returns/find', ['code' => $a->order_no])->assertJson(['ok' => false, 'level' => 'orange']);
        $this->postJson("/returns/{$a->id}", ['items' => [$items[$this->dates->id] => 'good', $items[$this->nuts->id] => 'good']])->assertStatus(422);
        $this->assertSame(2.0, $this->dates->fresh()->stock_qty);
        $b = $this->booked('01822222222', [['variant_id' => $this->nuts->id, 'qty' => 1]]);
        $this->postJson('/returns/find', ['code' => $b->order_no])->assertJson(['ok' => false, 'level' => 'red']);
        $this->get('/returns?tab=received')->assertSee($a->order_no)->assertSee('2 damaged');

        // Still not back after the setting's days: red, ask the courier.
        $this->returned($b, '-8 days');
        $this->get('/returns')->assertSee('Not back after 8 days');

        // The real reason is set from the Returns page; the report counts it, the loss and the damaged item.
        DB::table('order_items')->where('order_id', $a->id)->where('variant_id', $this->nuts->id)->update(['cost_price_snapshot' => 250]);
        DB::table('orders')->where('id', $a->id)->update(['return_charge' => 60]);
        $refused = (int) DB::table('status_reasons')->where('reason_type', 'return')->where('system_key', 'refused')->value('id');
        $this->actingAs($this->desk)->post("/returns/{$a->id}/reason", ['reason_id' => $refused])->assertSessionHas('success');
        $this->get('/returns-report')->assertOk()
            ->assertSee('Customer refused at door')->assertSee('Nuts')->assertSee($a->order_no)
            ->assertSee('৳310', false) // loss: courier ৳60 + damaged nuts ৳250
            ->assertSee('1 damaged');
        $this->actingAs($this->packer)->get('/returns-report')->assertForbidden();
    }

    public function test_a_partly_delivered_parcel_marks_what_the_customer_kept_and_no_alarm_goes_out(): void
    {
        $this->actingAs($this->desk)->postJson('/products/stock/'.$this->dates->id, ['qty' => 5]);
        $a = $this->booked('01833333333', [['variant_id' => $this->dates->id, 'qty' => 3]]);
        $this->actingAs($this->packer);
        $this->packIt($a);
        DB::table('orders')->where('id', $a->id)->update(['status_id' => OrderStatus::idFor('partial_delivered')]);
        $item = DB::table('order_items')->where('order_id', $a->id)->value('id');

        $this->postJson("/returns/{$a->id}", ['items' => [$item => ['kept' => 2, 'good' => 1]]])->assertOk();
        $this->assertSame(3.0, $this->dates->fresh()->stock_qty); // 5 - 3 packed + 1 back
        $this->assertSame(0, DB::table('app_notifications')->where('subject_id', $a->id)->where('title', 'like', '%damaged or missing%')->count());
        $this->get('/returns?tab=received')->assertSee('2 kept by the customer');
    }

    public function test_a_part_of_a_pack_is_counted_in_the_same_unit(): void
    {
        // 10 packs of dates sold; 0.9 of a pack came back good, the rest spoiled.
        $this->actingAs($this->desk)->postJson('/products/stock/'.$this->dates->id, ['qty' => 12]);
        $a = $this->booked('01844444444', [['variant_id' => $this->dates->id, 'qty' => 10]]);
        $this->actingAs($this->packer);
        $this->packIt($a);
        $this->returned($a);
        $item = DB::table('order_items')->where('order_id', $a->id)->value('id');

        $this->postJson("/returns/{$a->id}", ['items' => [$item => ['good' => 0.9, 'damaged' => 9]]])->assertStatus(422); // must add up to 10
        $this->postJson("/returns/{$a->id}", ['items' => [$item => ['good' => 0.9, 'damaged' => 9.1]]])->assertOk();
        $this->assertSame(2.9, $this->dates->fresh()->stock_qty);
        $this->assertSame(9.1, (float) DB::table('return_items')->where('order_id', $a->id)->where('condition', 'damaged')->value('qty'));

        // A count can be a part too, and under one whole pack it cannot fill an order.
        $this->actingAs($this->desk)->postJson('/products/stock/'.$this->dates->id, ['qty' => 0.9])->assertOk()->assertJson(['qty' => 0.9, 'status' => 'out_of_stock']);
    }

    public function test_a_box_to_open_is_settled_when_the_order_is_packed_again(): void
    {
        $this->actingAs($this->desk)->postJson('/products/stock/'.$this->dates->id, ['qty' => 5]);
        $a = $this->booked('01855555555', [['variant_id' => $this->dates->id, 'qty' => 2]]);
        $this->actingAs($this->packer);
        $this->packIt($a);
        $this->assertSame(3.0, $this->dates->fresh()->stock_qty);

        // Taken back after packing, then booked and packed again before anyone opened the old box (the same box went out).
        DB::table('orders')->where('id', $a->id)->update(['unpack_needed_at' => now(), 'unpack_packer_id' => $this->packer->id, 'status_id' => OrderStatus::idFor('ready_for_packaging'), 'packer_id' => null, 'packed_at' => null]);
        $this->packIt($a->fresh());
        $this->assertNull($a->fresh()->unpack_needed_at);
        $this->assertSame(3.0, $this->dates->fresh()->stock_qty); // taken once, not twice

        // Even if an old entry were left, Unpacked on a parcel that is on its way puts nothing back.
        DB::table('orders')->where('id', $a->id)->update(['unpack_needed_at' => now()]);
        app(\App\Services\Orders\DeskService::class)->markUnpacked($a->fresh(), $this->packer);
        $this->assertSame(3.0, $this->dates->fresh()->stock_qty);
        $this->assertNull($a->fresh()->unpack_needed_at);
    }

    public function test_a_label_typed_by_hand_is_marked_and_earns_no_packing_point(): void
    {
        (new \Database\Seeders\PointsSeeder)->run();
        $a = $this->booked('01866666666', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $b = $this->booked('01877777777', [['variant_id' => $this->dates->id, 'qty' => 1]]);
        $this->actingAs($this->packer);
        $items = fn (Order $o) => DB::table('order_items')->where('order_id', $o->id)->pluck('id')->all();

        // The card no longer shows the code to type.
        $this->getJson("/packaging/orders/{$a->id}")->assertOk();
        // Typed by hand: it opens, is logged as by hand, and packing it earns no point.
        $this->postJson('/packaging/scan', ['code' => $this->label($a), 'via' => 'typed'])->assertJson(['ok' => true]);
        $this->assertSame(1, (int) DB::table('scan_logs')->where('order_id', $a->id)->where('result', 'ok')->value('manual'));
        $this->postJson("/packaging/orders/{$a->id}/pack", ['items' => $items($a)])->assertJson(['ok' => true]);
        // Scanned: the usual point.
        $this->postJson('/packaging/scan', ['code' => $this->label($b), 'via' => 'scanner'])->assertJson(['ok' => true]);
        $this->postJson("/packaging/orders/{$b->id}/pack", ['items' => $items($b)])->assertJson(['ok' => true]);

        $points = fn (Order $o) => DB::table('point_ledger')->where('user_id', $this->packer->id)->where('order_id', $o->id)->count();
        $this->assertSame(0, $points($a));
        $this->assertSame(1, $points($b));
    }
}
