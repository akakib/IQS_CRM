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
            ->assertSee('Cancel 84%')->assertSee('Fake order ×2')->assertSee('Doubtful reports')->assertDontSee('Risk:');
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
        $customer->update(['delivered_count' => 3]);

        $order = $this->order('01912345670');

        // Called too: the call is also for upselling.
        $this->assertSame('record_verified', $this->key($order));
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id)->where('source', 'rule')->count());

        app(\App\Services\Orders\OrderStateMachine::class)->transition($order, 'confirmed', null, 'rule');
        $this->assertSame('350.00', $order->fresh()->items->first()->cost_price_snapshot);
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
