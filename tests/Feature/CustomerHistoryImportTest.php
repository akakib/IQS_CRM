<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\HistoryImport;
use App\Support\Phone;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/** Order history from a file: the columns are known by name, it imports on upload, never twice, and Undo takes it back. */
class CustomerHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;

    protected function setUp(): void
    {
        parent::setUp();
        (new CustomerSeeder)->run();
        $this->boss = $this->owner();
        $this->actingAs($this->boss);
    }

    /** Uploads a file; it imports at once. Returns the import id. */
    private function upload(string $name, string $csv): int
    {
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent($name, $csv)])->assertSessionHasNoErrors();
        $id = (int) DB::table('customer_imports')->max('id');
        $this->assertNotNull(DB::table('customer_imports')->find($id)->done_at);

        return $id;
    }

    private function counts(int $id): array
    {
        return json_decode(DB::table('customer_imports')->find($id)->result, true);
    }

    public function test_a_woocommerce_file_imports_on_upload_with_no_mapping_and_never_twice(): void
    {
        $known = Customer::create(['name' => 'Rahima Old', 'primary_phone' => '01712345678']);
        DB::table('customer_phones')->insert(['customer_id' => $known->id, 'phone' => '01712345678', 'is_primary' => true]);
        $woo = "Order Number,Order Date,Order Status,Billing First Name,Billing Last Name,Billing Phone,Order Total Amount,Item Name,Quantity,UTM Source\n"
            ."5521,2025-02-12 14:03,wc-completed,Rahima,Akter,+880 1712-345678,890.00,Medjool Dates 1kg,1,facebook\n"
            ."5521,2025-02-12 14:03,wc-completed,Rahima,Akter,+880 1712-345678,890.00,Honey 500g,2,facebook\n"
            ."5600,2025-03-20 10:00,wc-cancelled,Kamal,Hossain,01812345678,1450,Ajwa Dates,1,\n"
            ."5602,2025-03-22 10:00,wc-completed,Bad,Number,12345,500,Ajwa Dates,1,\n";

        $this->get('/customers/history')->assertOk()->assertSee('Download the sample file');
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('woo.csv', $woo)])
            ->assertRedirect(route('customers.history.show', 1));
        $this->assertEquals(['orders' => 2, 'known' => 1, 'new' => 1, 'bad_phone' => 1], array_intersect_key($this->counts(1), array_flip(['orders', 'known', 'new', 'bad_phone'])));
        $rahima = DB::table('customer_past_orders')->where('external_no', '5521')->first();
        $this->assertSame([$known->id, 'Medjool Dates 1kg, Honey 500g × 2', 'messenger', 'delivered'], [$rahima->customer_id, $rahima->items, $rahima->medium, $rahima->status]);
        $this->assertEquals(890, $rahima->amount);
        $kamal = Customer::where('primary_phone', '01812345678')->first();
        $this->assertSame('Kamal Hossain', $kamal->name);
        $this->assertSame('cancelled', DB::table('customer_past_orders')->where('customer_id', $kamal->id)->value('status'));
        $this->get(route('journey.show', $known))->assertOk()->assertSee('#5521')->assertSee('Imported: woo')->assertSee('৳890');

        // The same file again: nothing doubles.
        $again = $this->upload('woo.csv', $woo);
        $this->assertSame([0, 2], [$this->counts($again)['orders'], $this->counts($again)['already']]);
        $this->assertSame(2, DB::table('customer_past_orders')->count());

        // No Name or Phone column: refused with a pointer to the sample, nothing imported.
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('x.csv', "foo,bar\n1,2\n")])->assertSessionHasErrors('file');
        $this->assertSame(2, DB::table('customer_imports')->count());

        // Without the permission, no import.
        $mod = User::factory()->create();
        $mod->roles()->attach($this->role(['customers.view'], [], 'Mod')->id);
        $this->actingAs($mod)->get('/customers/history')->assertForbidden();
    }

    public function test_the_sample_file_round_trips(): void
    {
        $xlsx = $this->get('/customers/history/sample')->assertOk()->assertHeader('content-disposition', 'attachment; filename="iqs-orders-sample.xlsx"')->getContent();
        $path = tempnam(sys_get_temp_dir(), 's').'.xlsx';
        file_put_contents($path, $xlsx);
        [$header, $rows] = app(HistoryImport::class)->read($path, 'xlsx');
        $this->assertSame(['Name', 'CN', 'Phone', 'Address', 'Products', 'Amount', 'Date', 'Status'], $header);
        $this->assertSame('Rahima Akter', $rows[0][0]);

        // Filled in: an empty date and status, the example row skipped for its fake phone.
        $csv = "Name,CN,Phone,Address,Products,Amount,Date,Status\nRahima Akter,DM1AAAAA,01XXXXXXXXX,x,y,1,2026-07-24,Delivered\nKarim Uddin,,+8801712345678,Road 1,Ajwa 1 kg,900,,\nNusrat,DM2BBBBB,০১৯১২৩৪৫৬৭৮,Feni,Honey,1200,24/07/2026,Returned\n";
        $id = $this->upload('iqs-orders-sample.csv', $csv);
        $this->assertSame([2, 1], [$this->counts($id)['orders'], $this->counts($id)['bad_phone']]);
        $karim = DB::table('customer_past_orders')->where('amount', 900)->first();
        $this->assertSame(['delivered', now()->format('Y-m-d')], [$karim->status, substr($karim->ordered_at, 0, 10)]);
        $this->assertSame('01712345678', Customer::find($karim->customer_id)->primary_phone);
        $this->assertSame(['2026-07-24', 'returned'], [substr(DB::table('customer_past_orders')->where('cn', 'DM2BBBBB')->value('ordered_at'), 0, 10), DB::table('customer_past_orders')->where('cn', 'DM2BBBBB')->value('status')]);
    }

    public function test_an_excel_file_is_read_without_a_package(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Recipient Phone</t></si><si><t>Recipient Name</t></si><si><t>Created</t></si><si><t>Collectable Amount</t></si><si><t>01912345678</t></si><si><t>Nusrat Jahan</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" t="s"><v>5</v></c><c r="C2"><v>45700</v></c><c r="D2"><v>1250</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();
        $this->post('/customers/history', ['file' => new UploadedFile($path, 'pathao.xlsx', null, null, true)])->assertSessionHasNoErrors();
        $p = DB::table('customer_past_orders')->first();
        $this->assertSame(['2025-02-12', 'delivered', 'other'], [substr($p->ordered_at, 0, 10), $p->status, $p->medium]); // Excel day number 45700
        $this->assertEquals(1250, $p->amount);
        $this->assertSame('Nusrat Jahan', Customer::find($p->customer_id)->name);
    }

    public function test_a_pathao_export_is_read_right_cn_kept_and_found(): void
    {
        $csv = "consignment_id,merchant_name,recipient_name,recipient_phone,amount_to_collect,transfer_status,invoice_id,created_at,item_type,quantity\n"
            ." dm240726f65t8n ,Iqbal Store,Md Shahin Raza,1.326885301E9,0,Delivered,260726MQKJLAG,\"7/8/2026, 16:27\",Parcel,1.0\n"
            ."DM1BBBBB,Iqbal Store,Antara Das,1764121879,2180,Paid Return,260726MQKJLAG,\"7/24/2026, 16:26\",Parcel,1\n"
            ."DM1CCCCC,Iqbal Store,Kamal,01812345678,500,Pickup Cancelled,260726MQKJLAG,\"7/25/2026, 10:00\",Parcel,1\n"
            ."DM1DDDDD,Iqbal Store,Lost One,01912345678,500,Exchange,260726MQKJLAG,\"7/25/2026, 10:00\",Parcel,1\n"
            ."DM1EEEEE,Iqbal Store,,01612345678,500,Delivered,260726MQKJLAG,\"7/25/2026, 10:00\",Parcel,1\n";
        $id = $this->upload('1st 300.csv', $csv);
        // merchant_name is not the customer's name, invoice_id is not an order number, item_type is not a product.
        $this->assertSame('Md Shahin Raza', Customer::where('primary_phone', '01326885301')->value('name'));
        $this->assertEquals(['orders' => 3, 'skipped' => 1, 'no_name' => 1], array_intersect_key($this->counts($id), array_flip(['orders', 'skipped', 'no_name'])));
        $shahin = DB::table('customer_past_orders')->where('cn', 'DM240726F65T8N')->first();
        $this->assertSame(['2026-07-08', null, null, '1st 300'], [substr($shahin->ordered_at, 0, 10), $shahin->external_no, $shahin->items, $shahin->source]);
        $this->assertSame(['returned', 'cancelled'], [DB::table('customer_past_orders')->where('cn', 'DM1BBBBB')->value('status'), DB::table('customer_past_orders')->where('cn', 'DM1CCCCC')->value('status')]);

        $this->get('/journey?q='.urlencode('dm240726f65t8n'))->assertRedirect(route('journey.show', $shahin->customer_id));
        $this->get(route('journey.show', $shahin->customer_id))->assertSee('CN DM240726F65T8N');
        $this->get('/order-search?by=cn&q=DM240726F65T8N')->assertSee('Imported: 1st 300')->assertSee('Md Shahin Raza');

        // The same parcel from another file, with a newer amount: updated, not added; Undo puts the old amount back.
        $fixed = $this->upload('fixed.csv', "consignment_id,recipient_name,recipient_phone,amount_to_collect,created_at\nDM240726F65T8N,Md Shahin Raza,01326885301,1500,\"7/8/2026, 16:27\"\n");
        $this->assertSame([0, 1], [$this->counts($fixed)['orders'], $this->counts($fixed)['updated']]);
        $this->assertEquals(1500, DB::table('customer_past_orders')->where('cn', 'DM240726F65T8N')->value('amount'));
        $this->post(route('customers.history.undo', $fixed))->assertRedirect(route('customers.history.show', $fixed));
        $this->assertEquals(0, DB::table('customer_past_orders')->where('cn', 'DM240726F65T8N')->value('amount'));
    }

    public function test_undo_takes_back_the_orders_and_the_customers_it_made(): void
    {
        $old = Customer::create(['name' => 'Old Customer', 'primary_phone' => '01712345678']);
        DB::table('customer_phones')->insert(['customer_id' => $old->id, 'phone' => '01712345678', 'is_primary' => true]);
        $id = $this->upload('a.csv', "phone,name,date\n01712345678,Old Customer,2025-01-01\n01812345678,New One,2025-01-02\n");
        $this->assertSame(2, DB::table('customer_past_orders')->count());
        $this->get(route('customers.history.show', $id))->assertSee('Undo this import');

        $this->post(route('customers.history.undo', $id));
        $this->assertSame(0, DB::table('customer_past_orders')->count());
        $this->assertNull(Customer::where('primary_phone', '01812345678')->first()); // made by the import: gone
        $this->assertNotNull($old->fresh());                                         // was here before: stays
        $this->get(route('customers.history.show', $id))->assertSee('taken back')->assertDontSee('Undo this import');
    }

    public function test_keyless_rows_encoding_merges_and_odd_headers(): void
    {
        // A sheet with neither order number nor CN: the second upload is recognised, not doubled.
        $sheet = "phone,name,date,amount\n01712345678,Karim,2025-01-01,1200\n01712345678,Karim,,800\n";
        $this->upload('own.csv', $sheet);
        $again = $this->upload('own again.csv', $sheet);
        $this->assertSame([0, 2], [$this->counts($again)['orders'], $this->counts($again)['already']]);

        // Excel "CSV (Comma delimited)" in the Windows code page: read, not a crash.
        $this->upload('ansi.csv', "phone,name,date,amount\n01812345678,Caf\xE9 \x96 Shop,2025-02-01,500\n");
        $this->assertSame('Café – Shop', Customer::where('primary_phone', '01812345678')->value('name'));

        // Merging moves the imported history; the old journey link leads to the customer that is left.
        $karim = Customer::where('primary_phone', '01712345678')->first();
        $cafe = Customer::where('primary_phone', '01812345678')->first();
        app(\App\Services\Customers\CustomerService::class)->merge($karim, $cafe, $this->boss->id);
        $this->assertSame(3, DB::table('customer_past_orders')->where('customer_id', $karim->id)->count());
        $this->get(route('journey.show', $cafe->id))->assertRedirect(route('journey.show', $karim->id));

        // Two columns with one name keep apart.
        [$header] = app(HistoryImport::class)->read(tap(tempnam(sys_get_temp_dir(), 'c'), fn ($p) => file_put_contents($p, "Phone,Phone,Name\n017,018,X\n")), 'csv');
        $this->assertSame(['Phone', 'Phone (2)', 'Name'], $header);
    }

    public function test_phone_numbers_every_way_they_are_written(): void
    {
        $x = fn ($v) => Phone::extract($v);
        foreach (['01712345678', '1712345678', '+0 1712345678', '+88 1712345678', '+880 01712345678', '+88001712345678', '0088 01712345678', '0088001712345678', '08801712345678',
            '০১৭১২৩৪৫৬৭৮', '(+88) 017 1234 5678', 'Phone: 01712 345 678', '1.712345678E9', '1712345678.0'] as $in) {
            $this->assertSame(['01712345678'], $x($in), $in);
        }
        $this->assertSame(['01712345678', '01812345678'], $x('+880 1712-345678 / 01812345678 (husband)'));
        $this->assertSame(['01712345678', '01812345678'], $x('1. 01712345678 2. 01812345678'));
        // A digit too many or too few is never guessed at, nor completed by a stray digit after it.
        foreach (['017123456788', '0171234567', '8.80133E+12', '0171234567 x2', '0171234567 1/5/2026', '+880 171234567 8'] as $in) {
            $this->assertSame([], $x($in), $in);
        }
        $this->assertSame(['01712345678'], $x('01712345678 / 0181234567 (husband, 2nd)'));
    }

    public function test_labels_are_dropped_and_hand_filled_rows_come_out_clean(): void
    {
        $u = fn ($v) => HistoryImport::unlabel($v);
        foreach ([
            'Address: House 12, Road 5, Mirpur' => 'House 12, Road 5, Mirpur', 'Customer Name : Rahima Akter' => 'Rahima Akter', 'Name:- Rahima Akter' => 'Rahima Akter',
            'নাম: রহিমা' => 'রহিমা', 'গ্রাহকের নাম: রহিমা' => 'রহিমা', 'ক্রেতার নামঃ রহিমা' => 'রহিমা', 'ডেলিভারি ঠিকানা: বাসা ১২' => 'বাসা ১২',
            'Product name: Dates 1 kg' => 'Dates 1 kg', 'Tracking ID: DM1AAAAA' => 'DM1AAAAA', 'Order No.: 5521' => '5521', 'Date: 24/07/2026' => '24/07/2026',
            "Customer's Name: Rahima" => 'Rahima', 'Adress: House 12' => 'House 12', 'Qty: 2' => '2',
            // Content that only looks like a label stays.
            'Road: 5, Block B' => 'Road: 5, Block B', '10:30 delivery' => '10:30 delivery', 'Thana: Sonagazi, Dist: Feni' => 'Thana: Sonagazi, Dist: Feni', 'Zone: 4, Mirpur' => 'Zone: 4, Mirpur',
        ] as $in => $out) {
            $this->assertSame($out, $u($in), $in);
        }

        $csv = "Name,Phone,Address,Products,Date,Amount\n\"Name: Rahima Akter\",\"01712-345678 / 01812345678\",\"Address: House 12, Mirpur\",\"Products: Dates 1 kg\",Date: 2026-07-01,Tk. 1250\n"
            ."Rahima Akter,\"01812345678 / 01912345678\",Mirpur,Honey,2026-07-05,\"1,250+60\"\n";
        $this->upload('hand.csv', $csv);
        $c = Customer::where('primary_phone', '01712345678')->first();
        $this->assertSame('Rahima Akter', $c->name);
        // One person on two rows sharing a number: one customer with every number.
        $this->assertEqualsCanonicalizing(['01712345678', '01812345678', '01912345678'], DB::table('customer_phones')->where('customer_id', $c->id)->pluck('phone')->all());
        $p = DB::table('customer_past_orders')->where('customer_id', $c->id)->orderBy('ordered_at')->get();
        $this->assertSame(['House 12, Mirpur', 'Dates 1 kg', '2026-07-01'], [$p[0]->address, $p[0]->items, substr($p[0]->ordered_at, 0, 10)]);
        $this->assertEquals([1250, 1250], $p->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->get('/journey?q=01912345678')->assertRedirect(route('journey.show', $c->id));
    }

    public function test_remembered_picks_and_medium_are_used_without_asking(): void
    {
        DB::table('import_templates')->insert([
            ['name' => 'Old', 'signature' => 'a', 'mapping' => json_encode(['collected_amount' => 'amount', 'phone' => 'phone']), 'values' => '{}', 'default_medium' => 'messenger', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
            ['name' => 'New', 'signature' => 'b', 'mapping' => json_encode(['amount_to_collect' => 'amount', 'name' => 'name']), 'values' => '{}', 'default_medium' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->upload('x.csv', "phone,name,amount_to_collect,collected_amount\n01712345678,A,700,100\n");
        $p = DB::table('customer_past_orders')->first();
        $this->assertEquals(700, $p->amount);            // the newer pick for the amount, once
        $this->assertSame('messenger', $p->medium);       // the remembered medium
    }
}
