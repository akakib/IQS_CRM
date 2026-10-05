<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Marketing\AdCostService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $vendor;

    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        $this->owner = $this->owner();
        $this->vendor = DB::table('ad_vendors')->insertGetId(['name' => 'Dollar Bhai', 'is_active' => true]);
        $this->account = DB::table('ad_accounts')->insertGetId(['platform' => 'meta', 'name' => 'Main', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
    }

    private function lot(float $usd, float $rate, string $date, float $paid = 0, ?string $due = '2026-12-31'): void
    {
        app(AdCostService::class)->addLot(['vendor_id' => $this->vendor, 'purchased_on' => $date, 'usd' => $usd, 'rate' => $rate,
            'paid_bdt' => $paid, 'due_date' => $due, 'payment_method_id' => DB::table('payment_methods')->value('id')], $this->owner);
    }

    public function test_spend_uses_oldest_lot_first_and_rebuilds_when_a_day_changes(): void
    {
        $this->lot(100, 120, '2026-09-01');
        $this->lot(100, 125, '2026-09-10');
        $costs = app(AdCostService::class);
        $costs->saveSpend($this->account, '2026-09-20', ['spend_usd' => 80], 'manual');
        $costs->saveSpend($this->account, '2026-09-21', ['spend_usd' => 50], 'manual');
        $costs->rebuild();

        $cost = fn ($d) => (float) DB::table('ad_spend_daily')->where('spend_date', $d)->value('bdt_cost');
        $this->assertSame(9600.0, $cost('2026-09-20'));            // 80 x 120
        $this->assertSame(20 * 120 + 30 * 125.0, $cost('2026-09-21')); // rest of lot 1, then lot 2
        $this->assertSame(70.0, (float) DB::table('usd_lots')->sum('usd_remaining'));

        // A re-pull corrects the first day: everything after is re-costed.
        $costs->saveSpend($this->account, '2026-09-20', ['spend_usd' => 100], 'api');
        $costs->rebuild();
        $this->assertSame(12000.0, $cost('2026-09-20'));
        $this->assertSame(50 * 125.0, $cost('2026-09-21'));
    }

    public function test_spend_beyond_all_lots_is_flagged_unfunded(): void
    {
        $this->lot(10, 120, '2026-09-01');
        app(AdCostService::class)->saveSpend($this->account, '2026-09-02', ['spend_usd' => 15], 'manual');
        app(AdCostService::class)->rebuild();

        $row = DB::table('ad_spend_daily')->first();
        $this->assertSame(5.0, (float) $row->unfunded_usd);
        $this->assertSame(10 * 120 + 5 * 120.0, (float) $row->bdt_cost);
    }

    public function test_vendor_dues_and_reminder(): void
    {
        $this->lot(100, 120, today()->toDateString(), 5000, today()->toDateString());
        $v = app(AdCostService::class)->vendorBalances()->first();
        $this->assertSame(7000.0, $v->due);

        $this->artisan('vendors:due-reminders')->expectsOutputToContain('1 reminder')->assertSuccessful();
        $this->actingAs($this->owner)->post(route('usd-lots.payments.store'), ['vendor_id' => $this->vendor, 'amount_bdt' => 7000,
            'paid_on' => today()->toDateString(), 'payment_method_id' => DB::table('payment_methods')->value('id')])->assertSessionHas('success');
        $this->assertSame(0.0, app(AdCostService::class)->vendorBalances()->first()->due);
    }

    public function test_unpaid_lot_needs_a_due_date(): void
    {
        $this->actingAs($this->owner)->post(route('usd-lots.store'), ['vendor_id' => $this->vendor, 'purchased_on' => today()->toDateString(), 'usd' => 50, 'rate' => 122])
            ->assertSessionHasErrors('due_date');
    }

    public function test_csv_import_pull_and_pages(): void
    {
        $this->lot(1000, 122, '2026-09-01');
        $csv = UploadedFile::fake()->createWithContent('google.csv', "Day,Cost,Clicks,Impr.\n2026-10-01,25.50,40,3000\n2026-10-02,\"1,000\",10,100\nbad,x,1,1\n");
        $this->actingAs($this->owner)->post(route('marketing.import'), ['ad_account_id' => $this->account, 'file' => $csv])->assertSessionHas('success');
        $this->assertSame(2, DB::table('ad_spend_daily')->where('source', 'import')->count());
        $this->assertSame(25.5 * 122, (float) DB::table('ad_spend_daily')->where('spend_date', '2026-10-01')->value('bdt_cost'));

        $this->post(route('marketing.pull'))->assertSessionHas('success'); // fake driver, last 3 days
        $this->assertGreaterThanOrEqual(3, DB::table('ad_spend_daily')->where('source', 'fake')->count());
        $this->assertSame(DB::table('ad_spend_daily')->distinct()->count('spend_date'), DB::table('ad_cost_days')->count());

        $this->get(route('marketing.index', ['from' => '2026-09-25', 'to' => today()->toDateString()]))->assertOk()->assertSee('Delivered ROAS');
        $this->get(route('usd-lots.index'))->assertOk()->assertSee('Dollar Bhai');
        $this->get(route('analysis.index'))->assertOk()->assertSee('Profit after ads');
    }
}
