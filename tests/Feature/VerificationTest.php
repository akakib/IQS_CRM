<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\VerificationEngine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ConfirmationSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new ConfirmationSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        Product::factory()->withVariant(500, ['sku' => 'ALM', 'weight_g' => 500, 'cost_price' => 350])->create(['name' => 'Almonds']);
        $this->variant = ProductVariant::firstWhere('sku', 'ALM');
    }

    private function order(string $phone, int $qty = 1, array $extra = []): Order
    {
        $order = app(OrderService::class)->create($extra + [
            'channel' => 'web', 'phone' => $phone, 'name' => 'Buyer', 'address_line' => 'Road 1', 'thana' => 'Mirpur',
            'zone_id' => DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->variant->id, 'qty' => $qty]],
        ], null, 'webhook');
        app(VerificationEngine::class)->run($order);

        return $order->fresh();
    }

    private function key(Order $order): string
    {
        return OrderStatus::map()[$order->status_id]['key'];
    }

    public function test_good_courier_history_is_record_verified_and_the_reason_is_stored(): void
    {
        $order = $this->order('01712345678'); // fake: 13 parcels, 12 delivered

        $this->assertSame('record_verified', $this->key($order));
        $run = DB::table('verification_runs')->where('order_id', $order->id)->first();
        $this->assertSame('Good Steadfast history', DB::table('verification_rules')->where('id', $run->matched_rule_id)->value('name'));
        $inputs = json_decode($run->inputs_snapshot, true);
        $this->assertFalse($inputs['is_new_customer']);
        $this->assertEquals(92.31, collect($inputs['providers'])->firstWhere('key', 'steadfast')['success_rate']);
    }

    public function test_new_to_steadfast_waits_for_the_delivery_charge_in_advance(): void
    {
        $order = $this->order('01712345679', 1);   // ends in 9: no Steadfast parcels
        $this->assertSame('hold', $this->key($order));
        $this->assertSame('advance_wait', DB::table('status_reasons')->where('id', $order->hold_reason_id)->value('system_key'));
        $this->assertSame((float) $order->delivery_charge, (float) $order->advance_required);
    }

    public function test_an_order_confirmed_without_record_verified_is_set_on_the_website_and_shows_its_website_number(): void
    {
        \App\Services\Catalog\Store\FakeStoreDriver::$sent = [];
        $order = $this->order('01712345679', 1, ['external_ref' => '25288']); // held for the advance, never Record verified
        $this->assertSame('hold', $this->key($order));
        $owner = $this->owner();
        app(\App\Services\Orders\OrderStateMachine::class)->transition($order, 'confirmed', $owner, 'user', null, 'Advance waived');
        $this->app->terminate();
        (fn () => $this->terminatingCallbacks = [])->call($this->app);
        $this->assertContains(['order' => '25288', 'status' => 'record-verified'], \App\Services\Catalog\Store\FakeStoreDriver::$sent);

        $this->actingAs($owner)->get(route('orders.show', $order))->assertSee('Woo #25288');
        $this->get(route('orders.search', ['q' => '25288']))->assertSee($order->order_no);
        $this->get(route('orders.index'))->assertSee('Woo #25288');
    }

    public function test_a_record_verified_website_order_is_set_to_record_verified_on_the_website(): void
    {
        \App\Services\Catalog\Store\FakeStoreDriver::$sent = [];
        $order = $this->order('01712345678', 1, ['external_ref' => '9901']);
        $this->assertSame('record_verified', $this->key($order));
        $this->app->terminate(); // sent after the response
        (fn () => $this->terminatingCallbacks = [])->call($this->app); // the test app keeps it; a real request does not
        $this->assertContains(['order' => '9901', 'status' => 'record-verified'], \App\Services\Catalog\Store\FakeStoreDriver::$sent);
        $order->refresh();
        $this->assertSame('record-verified', $order->external_status);
        $this->assertNotNull($order->website_pushed_at);
        $this->assertNull($order->website_push_error);

        // The website refused: the reason stays on the order, the desk offers Send to website, and it goes once the website takes it.
        $mock = \Mockery::mock(\App\Services\Catalog\Store\StoreDriver::class);
        $mock->shouldReceive('name')->andReturn('woocommerce');
        $mock->shouldReceive('setOrderStatus')->once()->andThrow(new \RuntimeException('HTTP 500'));
        $mock->shouldReceive('setOrderStatus')->once();
        $this->app->instance(\App\Services\Catalog\Store\StoreDriver::class, $mock);
        $order->forceFill(['external_status' => 'processing'])->save();
        $this->assertFalse(app(\App\Services\Orders\WebsiteStatusPush::class)->push($order));
        $this->assertSame('HTTP 500', $order->fresh()->website_push_error);
        $this->actingAs($this->owner())->get(route('desk.index', ['order' => $order->id]))->assertSee('Website status:')->assertSee("\$dispatch('call-then-confirm')", false)->assertSee('Woo Record Verified')->assertSee('Not set:')->assertSee('HTTP 500');
        $this->get(route('orders.show', $order))->assertSee('Woo Record Verified');
        $this->postJson(route('orders.website-status', $order))->assertOk()->assertJson(['ok' => true, 'status' => 'Record Verified']);
        $this->get(route('desk.index', ['order' => $order->id]))->assertSee('Record Verified')->assertDontSee('Woo Record Verified');
        $this->assertNull($order->fresh()->website_push_error);
        // Never moves a finished website order back.
        $order->forceFill(['external_status' => 'completed'])->save();
        $this->assertFalse(app(\App\Services\Orders\WebsiteStatusPush::class)->applies($order->fresh()));
    }

    public function test_a_rule_can_pass_on_any_one_condition_and_ask_a_set_advance(): void
    {
        DB::table('verification_rules')->update(['is_active' => false]);
        $rule = DB::table('verification_rules')->insertGetId(['name' => 'Ours or Steadfast', 'priority' => 1, 'outcome' => 'record_verified', 'applies_to_channel' => 'all',
            'match_mode' => 'any', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('verification_rule_conditions')->insert([
            ['rule_id' => $rule, 'field' => 'own_success_rate', 'operator' => '>=', 'value' => '80', 'provider_id' => null],
            ['rule_id' => $rule, 'field' => 'any_provider_success_rate', 'operator' => '>=', 'value' => '99', 'provider_id' => null],
        ]);
        DB::table('verification_rules')->insert(['name' => 'Rest pay 20%', 'priority' => 2, 'outcome' => 'hold_for_advance', 'applies_to_channel' => 'all',
            'match_mode' => 'all', 'advance_type' => 'percent', 'advance_value' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Steadfast 92% (under 99) and nothing with us yet: neither passes, so 20% of the total in advance.
        $held = $this->order('01712345678');
        $this->assertSame('hold', $this->key($held));
        $this->assertEquals(ceil((float) $held->grand_total * 0.2), (float) $held->advance_required);

        // With us 4 of 5 delivered (80%): one condition is enough (counts set after the order, as delivery would).
        Customer::find($held->customer_id)->forceFill(['iqs_delivered_count' => 4, 'iqs_returned_count' => 1])->save();
        $this->assertSame('record_verified', app(VerificationEngine::class)->evaluate($held->fresh())['outcome']);
        DB::table('verification_rules')->where('id', $rule)->update(['match_mode' => 'all']);
        $this->assertSame('hold_for_advance', app(VerificationEngine::class)->evaluate($held->fresh())['outcome']);
    }

    public function test_advance_hold_needs_the_delivery_charge_or_an_admins_yes(): void
    {
        (new \Database\Seeders\RoleSeeder)->run();
        $mod = \App\Models\User::factory()->create();
        $admin = \App\Models\User::factory()->create();
        $mod->roles()->attach(\App\Models\Role::firstWhere('system_key', 'moderator')->id);
        $admin->roles()->attach(\App\Models\Role::firstWhere('system_key', 'manager')->id);
        app(\App\Services\PermissionService::class)->bump();
        $bkash = DB::table('payment_methods')->where('system_key', 'bkash')->value('id');

        // 1. The moderator cannot just resume it.
        $a = $this->order('01712345679');
        $a->forceFill(['moderator_id' => $mod->id])->save();
        $this->actingAs($mod);
        try {
            app(\App\Services\Orders\OrderStateMachine::class)->transition($a->fresh(), 'record_verified', $mod);
            $this->fail('Resumed without advance');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('needs ৳', collect($e->errors())->flatten()->first());
        }
        $this->get("/orders/{$a->id}")->assertSee('Advance needed')->assertSee('Ask admin: process without advance');

        // 2. The delivery charge comes in: the order goes to Call by itself.
        $this->post("/orders/{$a->id}/payments", ['advance' => ['method_id' => $bkash, 'amount' => (float) $a->advance_required, 'transaction_id' => 'DC1']]);
        $this->assertSame('record_verified', $this->key($a->fresh()));

        // 3. Asked without advance: the admin allows, it goes to Call.
        $b = $this->order('01812345679');
        $b->forceFill(['moderator_id' => $mod->id])->save();
        $this->post("/orders/{$b->id}/advance-waiver", ['why' => 'Old shop customer'])->assertSessionHas('success');
        $this->assertNotNull($b->fresh()->advance_waiver_requested_at);
        $this->post("/orders/{$b->id}/advance-waiver/decide", ['decision' => 'allow'])->assertForbidden();
        $this->actingAs($admin)->get("/orders/{$b->id}")->assertSee('Old shop customer')->assertSee('Process without advance');
        $this->post("/orders/{$b->id}/advance-waiver/decide", ['decision' => 'allow'])->assertSessionHas('success');
        $this->assertSame('record_verified', $this->key($b->fresh()));
        $this->assertNotNull($b->fresh()->advance_waived_by);
    }

    public function test_real_steadfast_score_is_read_from_the_score_endpoint(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*fraud_check/score/*' => \Illuminate\Support\Facades\Http::response(['delivery_ratio' => 81.5, 'cancellation_ratio' => 10, 'return_ratio' => 8.5, 'total_reports' => 0, 'volume_range' => '25+'])]);
        $r = (new \App\Services\Courier\SteadfastDriver(['api_key' => 'k', 'secret_key' => 's', 'base_url' => 'https://portal.packzy.com/api/v1']))->fraudCheck('01712345678');
        $this->assertSame(81.5, $r->successRate);
        $this->assertSame(10, $r->raw['cancellation_ratio']);

        // The rest of the score, small, on the order and the desk.
        $this->blade('<x-steadfast-detail :detail="$d" />', ['d' => ['cancellation_ratio' => 84, 'return_ratio' => 84, 'fraud_categories' => ['fake_order' => 2], 'doubtful_reports' => true, 'level' => null]])
            ->assertDontSee('Cancel 84%')->assertSee('Fake order ×2')->assertSee('Doubtful reports')->assertDontSee('Risk:');
        $this->blade('<x-steadfast-line :provider="$p" />', ['p' => ['key' => 'steadfast', 'success_rate' => 97.0, 'total_parcels' => 25, 'detail' => ['cancellation_ratio' => 2, 'volume_range' => '25+']]])
            ->assertSeeInOrder(['text-green-700', 'D 97%', 'text-red-600', 'C 2%', '25+ parcels'], false);
        $this->assertSame(25, $r->totalParcels); // from volume_range; total_reports (0 here) is not parcels
        \Illuminate\Support\Facades\Http::assertSent(fn ($req) => str_ends_with($req->url(), 'fraud_check/score/01712345678') && $req->hasHeader('Api-Key', 'k'));
    }

    public function test_weak_history_below_75_percent_waits_for_the_advance(): void
    {
        $order = $this->order('01700000000'); // 37.5% on 8 parcels

        $this->assertSame('hold', $this->key($order));
        $this->assertSame('Weak Steadfast history', DB::table('verification_rules')->where('id', DB::table('verification_runs')->where('order_id', $order->id)->value('matched_rule_id'))->value('name'));
    }

    public function test_trusted_repeat_customer_still_gets_a_call_and_cost_is_frozen_on_confirm(): void
    {
        $customer = Customer::create(['name' => 'Loyal', 'primary_phone' => '01912345670']);
        DB::table('customer_phones')->insert(['customer_id' => $customer->id, 'phone' => '01912345670', 'is_primary' => true]);
        // Three orders IQS itself delivered (the courier said so).
        foreach ([1, 2, 3] as $n) {
            $earlier = $this->order('01912345670');
            DB::table('orders')->where('id', $earlier->id)->update(['status_id' => OrderStatus::idFor('delivered')]);
        }
        \App\Services\Customers\CustomerStats::refresh([$customer->id]);

        $order = $this->order('01912345670');

        // Called too: the call is also for upselling.
        $this->assertSame('record_verified', $this->key($order));
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('source', 'rule')->count());

        app(\App\Services\Orders\OrderStateMachine::class)->transition($order, 'confirmed', null, 'rule');
        $this->assertSame('350.00', $order->fresh()->items->first()->cost_price_snapshot);
    }

    public function test_deliveries_from_an_imported_file_never_make_a_customer_trusted(): void
    {
        $customer = Customer::create(['name' => 'From a file', 'primary_phone' => '01912345671']);
        DB::table('customer_phones')->insert(['customer_id' => $customer->id, 'phone' => '01912345671', 'is_primary' => true]);
        foreach ([1, 2, 3] as $n) { // typed into a file: not proof
            DB::table('customer_past_orders')->insert(['customer_id' => $customer->id, 'source' => 'old', 'external_no' => 'F'.$n, 'ordered_at' => now()->subMonths($n), 'status' => 'delivered', 'created_at' => now(), 'updated_at' => now()]);
        }
        \App\Services\Customers\CustomerStats::refresh([$customer->id]);

        $order = $this->order('01912345671');
        $inputs = json_decode(DB::table('verification_runs')->where('order_id', $order->id)->value('inputs_snapshot'), true);
        $this->assertSame([0, 3], [$inputs['own_delivered_count'], $inputs['imported_delivered_count']]);
        $this->assertNotSame('Trusted repeat customer', DB::table('verification_rules')->where('id', DB::table('verification_runs')->where('order_id', $order->id)->value('matched_rule_id'))->value('name'));
        // People still see the whole picture: 4 orders, 3 delivered (all from the file).
        $this->assertSame([4, 3, 0, 1], [$customer->fresh()->orders_count, $customer->fresh()->delivered_count, $customer->fresh()->iqs_delivered_count, $customer->fresh()->iqs_orders_count]);
    }

    public function test_blocked_customer_always_goes_to_manual_review(): void
    {
        $customer = Customer::create(['name' => 'Blocked', 'primary_phone' => '01912345678', 'risk_level' => 'blocked']);
        DB::table('customer_phones')->insert(['customer_id' => $customer->id, 'phone' => '01912345678', 'is_primary' => true]);
        $customer->update(['delivered_count' => 9]);

        $this->assertSame('new', $this->key($this->order('01912345678')));
    }

    public function test_confirming_with_pre_order_item_holds_and_counts_against_the_limit(): void
    {
        $this->variant->update(['availability_status' => 'backorder', 'backorder_limit_qty' => 2, 'expected_restock_date' => now()->addDays(5)->toDateString()]);
        $order = $this->order('01712345678', 2);
        $this->assertSame('record_verified', $this->key($order));

        app(OrderStateMachine::class)->transition($order, 'confirmed', $this->owner());

        $order->refresh();
        $this->assertSame('hold', $this->key($order));
        $this->assertSame('awaiting_stock', DB::table('status_reasons')->where('id', $order->hold_reason_id)->value('system_key'));
        $this->assertNotNull($order->hold_expected_date);
        $this->variant->refresh();
        $this->assertSame('2.000', $this->variant->backorder_taken_qty);
        $this->assertSame('out_of_stock', $this->variant->availability_status); // limit reached
    }

    public function test_rules_screen_adds_a_rule_and_tests_an_order(): void
    {
        $order = $this->order('01712345678');
        $this->actingAs($this->owner());

        $this->get('/settings/verification')->assertOk()->assertSee('Good Steadfast history');
        $this->post('/settings/verification', [
            'name' => 'Big web orders need advance', 'priority' => 5, 'outcome' => 'hold_for_advance', 'applies_to_channel' => 'web',
            'conditions' => [['field' => 'order_total', 'operator' => '>', 'value' => '400']],
        ])->assertSessionHas('success');

        $this->post('/settings/verification/test', ['order_no' => $order->order_no])
            ->assertSessionHas('verification_test', fn ($t) => $t['rule'] === 'Big web orders need advance' && $t['outcome'] === 'hold_for_advance');
        $this->assertSame('record_verified', $this->key($order->fresh())); // a test changes nothing
    }
}
