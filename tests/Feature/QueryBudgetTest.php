<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The status list is read from the cache once per request, however often a page asks for it. */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_map_is_read_once_per_request(): void
    {
        (new OrderConfigSeeder)->run();
        config(['cache.default' => 'database']); // like the server
        Cache::flush();
        OrderStatus::forget();
        OrderStatus::map();
        DB::enableQueryLog();
        for ($i = 0; $i < 50; $i++) {
            OrderStatus::idFor('hold');
            OrderStatus::map();
        }
        $this->assertCount(0, DB::getQueryLog());

        // An edited status is seen at once.
        OrderStatus::where('system_key', 'hold')->update(['name_en' => 'On hold']);
        OrderStatus::forget();
        $this->assertSame('On hold', OrderStatus::map()[OrderStatus::idFor('hold')]['name']);
    }

    public function test_raw_webhook_bodies_are_cleared_after_the_configured_days_rows_and_orders_stay(): void
    {
        (new OrderConfigSeeder)->run();
        app(\App\Services\SettingsService::class)->set(['data.raw_payload_days' => 365]);
        DB::table('integration_inbox')->insert([
            ['source' => 'woocommerce', 'external_id' => 'old', 'payload' => '{"id":1}', 'status' => 'processed', 'received_at' => now()->subDays(400), 'processed_at' => now()->subDays(400)],
            ['source' => 'woocommerce', 'external_id' => 'new', 'payload' => '{"id":2}', 'status' => 'processed', 'received_at' => now()->subDays(10), 'processed_at' => now()->subDays(10)],
        ]);
        DB::table('courier_events')->insert([
            ['courier' => 'fake', 'consignment_id' => '1', 'payload' => '{"a":1}', 'idempotency_key' => 'k1', 'created_at' => now()->subDays(400)],
            ['courier' => 'fake', 'consignment_id' => '2', 'payload' => '{"a":2}', 'idempotency_key' => 'k2', 'created_at' => now()->subDays(1)],
        ]);
        $this->artisan('data:trim-raw-payloads')->assertSuccessful();
        $this->assertSame('{"trimmed":true}', DB::table('integration_inbox')->where('external_id', 'old')->value('payload'));
        $this->assertSame('{"id":2}', DB::table('integration_inbox')->where('external_id', 'new')->value('payload'));
        $this->assertSame(2, DB::table('integration_inbox')->count()); // rows stay
        $this->assertSame('{"trimmed":true}', DB::table('courier_events')->where('consignment_id', '1')->value('payload'));
        $this->assertSame('{"a":2}', DB::table('courier_events')->where('consignment_id', '2')->value('payload'));
        $this->artisan('data:trim-raw-payloads')->expectsOutputToContain('Cleared 0'); // already done: nothing twice
    }
}
