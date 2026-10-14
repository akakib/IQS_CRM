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

    public function test_typing_mistakes_are_fixed_or_deleted(): void
    {
        $this->actingAs($this->owner)->put(route('usd-lots.vendors.update', $this->vendor), ['name' => 'Dollar Bhai Ctg', 'phone' => '01712345678'])->assertSessionHas('success');
        $this->assertSame('Dollar Bhai Ctg', DB::table('ad_vendors')->where('id', $this->vendor)->value('name'));
        $this->put(route('marketing.accounts.update', $this->account), ['name' => 'Main page', 'external_id' => 'act_1'])->assertSessionHas('success');
        $this->assertSame('act_1', DB::table('ad_accounts')->where('id', $this->account)->value('external_id'));

        // A lot whose dollars were used stays; an untouched one goes.
        $this->lot(100, 120, '2026-09-01');
        $this->lot(50, 125, '2026-09-02');
        app(AdCostService::class)->saveSpend($this->account, '2026-09-20', ['spend_usd' => 10], 'manual');
        app(AdCostService::class)->rebuild();
        [$used, $fresh] = DB::table('usd_lots')->orderBy('id')->pluck('id')->all();
        $this->get(route('usd-lots.index'))->assertOk()->assertSee('lot-del-'.$fresh, false)->assertDontSee('lot-del-'.$used.'"', false);
        $this->delete(route('usd-lots.destroy', $used))->assertSessionHas('error');
        $this->delete(route('usd-lots.destroy', $fresh))->assertSessionHas('success');
        $this->assertSame(1, DB::table('usd_lots')->count());

        $this->post(route('usd-lots.payments.store'), ['vendor_id' => $this->vendor, 'amount_bdt' => 500, 'paid_on' => today()->toDateString(), 'payment_method_id' => DB::table('payment_methods')->value('id')]);
        $pay = DB::table('vendor_payments')->value('id');
        $this->delete(route('usd-lots.payments.destroy', $pay))->assertSessionHas('success');
        $this->assertSame(0, DB::table('vendor_payments')->count());
    }

    public function test_vendor_opening_advance_ledger_and_next_due(): void
    {
        $this->actingAs($this->owner);
        $method = DB::table('payment_methods')->value('id');
        // A new vendor who already held ৳5,000 of our money before the app.
        $this->post(route('usd-lots.vendors.store'), ['name' => 'Dollar Ghor', 'opening_amount' => 5000, 'opening_side' => 'advance', 'opening_on' => '2026-09-01'])->assertSessionHas('success');
        $v = (int) DB::table('ad_vendors')->where('name', 'Dollar Ghor')->value('id');
        $this->assertSame(-5000.0, (float) DB::table('ad_vendors')->where('id', $v)->value('opening_balance'));

        // 100 dollars at 122 = 12,200; the advance covers 5,000, so 7,200 is owed by the lot's due date.
        $this->post(route('usd-lots.store'), ['vendor_id' => $v, 'purchased_on' => '2026-09-05', 'usd' => 100, 'rate' => 122, 'due_date' => '2026-09-20']);
        $bal = fn () => app(AdCostService::class)->vendorBalances()->firstWhere('id', $v);
        $this->assertSame([7200.0, 0.0, '2026-09-20'], [$bal()->due, $bal()->advance, $bal()->next_due]);

        // Paying 10,000 clears it and leaves 2,800 as our advance.
        $this->post(route('usd-lots.payments.store'), ['vendor_id' => $v, 'amount_bdt' => 10000, 'paid_on' => '2026-09-10', 'payment_method_id' => $method]);
        $this->assertSame([0.0, 2800.0, null], [$bal()->due, $bal()->advance, $bal()->next_due]);

        $this->get(route('usd-lots.index'))->assertOk()->assertSee('Advance ৳2,800')->assertSee('Our advance with vendors');
        $this->get(route('usd-lots.vendor', $v))->assertOk()->assertSee('Opening balance')->assertSee('we owe ৳7,200')->assertSee('advance ৳2,800');

        // Editing the name keeps the opening balance (the form sends it back).
        $this->put(route('usd-lots.vendors.update', $v), ['name' => 'Dollar Ghor Ctg', 'opening_amount' => 5000, 'opening_side' => 'advance', 'opening_on' => '2026-09-01']);
        $this->assertSame(-5000.0, (float) DB::table('ad_vendors')->where('id', $v)->value('opening_balance'));
    }

    public function test_an_ads_manager_csv_gives_campaign_adset_and_ad_results(): void
    {
        $this->lot(1000, 120, '2026-09-01');
        DB::table('ad_accounts')->where('id', $this->account)->update(['external_id' => 'act_111']);
        $csv = "\xEF\xBB\xBFAccount ID,Campaign name,Ad set name,Ad name,Day,Amount spent (USD),Results,Result indicator,Impressions,Link clicks,Messaging conversations started\n"
            ."111,Dates Oct,Dhaka 25-45,Video A,2026-10-01,10.00,20,actions:onsite_conversion.messaging_conversation_started_7d,5000,90,20\n"
            ."111,Dates Oct,Dhaka 25-45,Video B,2026-10-01,5.00,5,actions:onsite_conversion.messaging_conversation_started_7d,2000,30,5\n"
            ."111,Nuts,All BD,Photo,2026-10-02,4.00,2,actions:offsite_conversion.fb_pixel_purchase,1000,10,0\n"
            ."999,Other,X,Y,2026-10-02,1.00,1,a,1,1,1\n"
            .",,,,,20.00,,,,,\n";
        $this->actingAs($this->owner)->post(route('campaigns.import'), ['file' => UploadedFile::fake()->createWithContent('ads.csv', $csv), 'fill_daily' => 1])
            ->assertSessionHas('error'); // one unknown account id is reported
        $this->assertSame(3, DB::table('ad_insights')->count());
        // The account's daily spend came from the same file, and its taka cost from the lot.
        $this->assertSame(15.0, (float) DB::table('ad_spend_daily')->where('spend_date', '2026-10-01')->value('spend_usd'));
        $this->assertSame(1800.0, (float) DB::table('ad_spend_daily')->where('spend_date', '2026-10-01')->value('bdt_cost'));

        $q = ['from' => '2026-10-01', 'to' => '2026-10-02'];
        $this->get(route('campaigns.index', $q))->assertOk()->assertSee('Dates Oct')->assertSee('$15.00')->assertSee('৳1,800')->assertSee('৳72'); // 1,800 / 25 results
        $this->get(route('campaigns.index', $q + ['level' => 'ad']))->assertOk()->assertSee('Video B')->assertSee('Dates Oct › Dhaka 25-45');

        // The same file again replaces, it does not add.
        $this->post(route('campaigns.import'), ['file' => UploadedFile::fake()->createWithContent('ads.csv', $csv), 'fill_daily' => 1]);
        $this->assertSame(3, DB::table('ad_insights')->count());

        $this->post(route('campaigns.import'), ['file' => UploadedFile::fake()->createWithContent('bad.csv', "Name,Cost\nA,1\n")])->assertSessionHasErrors('file');
    }

    public function test_an_advance_covers_lots_and_one_ad_is_not_counted_twice(): void
    {
        $this->actingAs($this->owner);
        // Opening advance 10,000; lot 1 8,000 due 5 Oct is covered, lot 2 12,000 due 20 Oct is the next due.
        DB::table('ad_vendors')->where('id', $this->vendor)->update(['opening_balance' => -10000, 'opening_on' => '2026-09-01']);
        $this->post(route('usd-lots.store'), ['vendor_id' => $this->vendor, 'purchased_on' => '2026-09-02', 'usd' => 80, 'rate' => 100, 'due_date' => '2026-10-05']);
        $this->post(route('usd-lots.store'), ['vendor_id' => $this->vendor, 'purchased_on' => '2026-09-03', 'usd' => 120, 'rate' => 100, 'due_date' => '2026-10-20']);
        $v = app(AdCostService::class)->vendorBalances()->firstWhere('id', $this->vendor);
        $this->assertSame([10000.0, '2026-10-20'], [$v->due, $v->next_due]);

        // The same ad as day rows, then as a whole-month row: the month row is refused, not added.
        DB::table('ad_accounts')->where('id', $this->account)->update(['external_id' => 'act_5']);
        $daily = "Account ID,Campaign name,Ad name,Day,Amount spent (USD),Results\n5,C1,A1,2026-10-01,10,2\n";
        $this->post(route('campaigns.import'), ['file' => UploadedFile::fake()->createWithContent('d.csv', $daily)]);
        $month = "Account ID,Campaign name,Ad name,Reporting starts,Reporting ends,Amount spent (USD),Results\n5,C1,A1,2026-10-01,2026-10-31,300,60\n";
        $this->post(route('campaigns.import'), ['file' => UploadedFile::fake()->createWithContent('m.csv', $month)])->assertSessionHas('error');
        $this->assertSame(10.0, (float) DB::table('ad_insights')->sum('spend_usd'));
    }

    public function test_daily_import_adds_rows_of_one_day_and_refuses_a_month_report(): void
    {
        $this->lot(1000, 120, '2026-09-01');
        $this->actingAs($this->owner);
        // One row per campaign for the same day: added up, not the last one only.
        $daily = "Day,Campaign name,Amount spent (USD)\n2026-09-02,A,10\n2026-09-02,B,5.5\n2026-09-03,A,4\n";
        $this->post(route('marketing.import'), ['ad_account_id' => $this->account, 'file' => UploadedFile::fake()->createWithContent('d.csv', $daily)])->assertSessionHas('success');
        $this->assertSame(15.5, (float) DB::table('ad_spend_daily')->where('spend_date', '2026-09-02')->value('spend_usd'));
        $this->assertSame(4.0, (float) DB::table('ad_spend_daily')->where('spend_date', '2026-09-03')->value('spend_usd'));

        // A month by campaign is refused with the way to do it, and saves nothing.
        $month = "\"Reporting starts\",\"Reporting ends\",\"Campaign name\",\"Amount spent (USD)\"\n2026-09-01,2026-09-30,A,1582.79\n2026-09-01,2026-09-30,B,0\n";
        $this->post(route('marketing.import'), ['ad_account_id' => $this->account, 'file' => UploadedFile::fake()->createWithContent('m.csv', $month)])
            ->assertSessionHas('error');
        $this->assertNull(DB::table('ad_spend_daily')->where('spend_date', '2026-09-01')->value('spend_usd'));
    }
}
