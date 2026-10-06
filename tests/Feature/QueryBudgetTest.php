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
}
