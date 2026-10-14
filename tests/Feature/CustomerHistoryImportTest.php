<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/** Order history from any CSV or Excel: columns guessed and mapped, words given a meaning, previewed, imported once. */
class CustomerHistoryImportTest extends TestCase
{
    use RefreshDatabase;

    private const WOO = "Order Number,Order Date,Order Status,Billing First Name,Billing Last Name,Billing Phone,Order Total Amount,Item Name,Quantity,UTM Source\n"
        ."5521,2025-02-12 14:03,wc-completed,Rahima,Akter,+880 1712-345678,890.00,Medjool Dates 1kg,1,facebook\n"
        ."5521,2025-02-12 14:03,wc-completed,Rahima,Akter,+880 1712-345678,890.00,Honey 500g,2,facebook\n"
        ."5600,2025-03-20 10:00,wc-cancelled,Kamal,Hossain,01812345678,1450,Ajwa Dates,1,\n"
        ."5601,2025-03-21 10:00,wc-processing,Kamal,Hossain,01812345678,500,Ajwa Dates,1,\n"
        ."5602,2025-03-22 10:00,wc-completed,Bad,Number,12345,500,Ajwa Dates,1,\n";

    public function test_a_woocommerce_csv_is_mapped_previewed_and_imported_once(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $known = Customer::create(['name' => 'Rahima Old', 'primary_phone' => '01712345678']);
        DB::table('customer_phones')->insert(['customer_id' => $known->id, 'phone' => '01712345678', 'is_primary' => true]);

        $this->actingAs($owner)->get('/customers/history')->assertOk();
        $r = $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('woo.csv', self::WOO)]);
        $id = DB::table('customer_imports')->value('id');
        $r->assertRedirect(route('customers.history.map', $id));
        // Guessed: phone, both name columns, order number, status words.
        $this->get(route('customers.history.map', $id))->assertOk()->assertSee('Billing Phone')->assertSee('wc-completed')->assertSee('auto');

        $body = ['source' => 'WooCommerce', 'mapping' => [
            'Order Number' => 'order_no', 'Order Date' => 'date', 'Order Status' => 'status', 'Billing First Name' => 'name', 'Billing Last Name' => 'name',
            'Billing Phone' => 'phone', 'Order Total Amount' => 'amount', 'Item Name' => 'items', 'Quantity' => 'qty', 'UTM Source' => 'medium',
        ], 'values' => ['status' => ['wc-completed' => 'delivered', 'wc-cancelled' => 'cancelled', 'wc-processing' => 'skip'], 'medium' => ['facebook' => 'messenger']],
            'default_medium' => 'web'];
        $this->postJson(route('customers.history.preview', $id), ['source' => 'x', 'mapping' => ['Billing Phone' => 'name']])->assertStatus(422);
        $this->postJson(route('customers.history.preview', $id), $body)->assertOk()
            ->assertJsonPath('counts.orders', 2)->assertJsonPath('counts.known', 1)->assertJsonPath('counts.new', 1)
            ->assertJsonPath('counts.bad_phone', 1)->assertJsonPath('counts.skipped', 1);
        $this->assertSame(0, DB::table('customer_past_orders')->count()); // a preview writes nothing

        $this->postJson(route('customers.history.preview', $id), $body); $this->postJson(route('customers.history.run', $id), $body)->assertOk()->assertJsonPath('url', route('customers.history.show', $id));
        $rahima = DB::table('customer_past_orders')->where('external_no', '5521')->first();
        $this->assertSame($known->id, $rahima->customer_id);
        $this->assertSame('Medjool Dates 1kg, Honey 500g × 2', $rahima->items);
        $this->assertSame('messenger', $rahima->medium);
        $this->assertEquals(890, $rahima->amount);
        $kamal = Customer::where('primary_phone', '01812345678')->first();
        $this->assertSame('Kamal Hossain', $kamal->name);
        $this->assertSame('cancelled', DB::table('customer_past_orders')->where('customer_id', $kamal->id)->value('status'));

        // The journey shows it; the same file again is matched by its template and nothing doubles.
        $this->get(route('journey.show', $known))->assertOk()->assertSee('#5521')->assertSee('Imported: WooCommerce')->assertSee('৳890');
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('woo2.csv', self::WOO)]);
        $second = DB::table('customer_imports')->max('id');
        $this->get(route('customers.history.map', $second))->assertSee('saved');
        $this->postJson(route('customers.history.preview', $second), $body)->assertJsonPath('counts.orders', 0)->assertJsonPath('counts.already', 2);
        $this->postJson(route('customers.history.preview', $id), $body); $this->postJson(route('customers.history.run', $id), $body)->assertStatus(422);

        // Without the permission, no import.
        $mod = User::factory()->create();
        $mod->roles()->attach($this->role(['customers.view'], [], 'Mod')->id);
        $this->actingAs($mod)->get('/customers/history')->assertForbidden();
    }

    public function test_an_excel_file_is_read_without_a_package(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Recipient Phone</t></si><si><t>Recipient Name</t></si><si><t>Created</t></si><si><t>Collectable Amount</t></si><si><t>01912345678</t></si><si><t>Nusrat Jahan</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2" t="s"><v>5</v></c><c r="C2"><v>45700</v></c><c r="D2"><v>1250</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $this->actingAs($owner)->post('/customers/history', ['file' => new UploadedFile($path, 'pathao.xlsx', null, null, true)]);
        $id = DB::table('customer_imports')->value('id');
        $body = ['source' => 'Pathao', 'default_medium' => 'messenger',
            'mapping' => ['Recipient Phone' => 'phone', 'Recipient Name' => 'name', 'Created' => 'date', 'Collectable Amount' => 'amount']];
        $this->postJson(route('customers.history.preview', $id), $body); $this->postJson(route('customers.history.run', $id), $body)->assertOk();
        $p = DB::table('customer_past_orders')->first();
        $this->assertSame('2025-02-12', substr($p->ordered_at, 0, 10)); // Excel day number 45700
        $this->assertSame('delivered', $p->status);                       // no status column: delivered
        $this->assertSame('messenger', $p->medium);
        $this->assertSame('Nusrat Jahan', Customer::find($p->customer_id)->name);
    }

    public function test_excel_numbers_month_first_dates_and_paid_return(): void
    {
        $owner = $this->owner();
        $csv = "recipient_phone,recipient_name,created_at,quantity,transfer_status\n1.326885301E9,A,\"7/24/2026, 16:27\",1.0,Paid Return\n1.926885301E9,B,\"7/8/2026, 09:05\",1.0,Delivered\n";
        $this->actingAs($owner)->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)]);
        $id = DB::table('customer_imports')->value('id');
        $r = $this->postJson(route('customers.history.preview', $id), ['source' => 'Pathao', 'default_medium' => 'web',
            'mapping' => ['recipient_phone' => 'phone', 'recipient_name' => 'name', 'created_at' => 'date', 'quantity' => 'qty', 'transfer_status' => 'status']]);
        $this->assertSame(2, $r->json('counts.orders'));
        $s = $r->json('sample');
        $this->assertSame(['01326885301', '2026-07-24 16:27:00', 'returned'], [$s[0]['phone'], $s[0]['ordered_at'], $s[0]['status']]);
        $this->assertSame('2026-07-08 09:05:00', $s[1]['ordered_at']);
    }

    public function test_cn_is_kept_in_one_shape_and_found_by_search_name_is_a_must(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $csv = "consignment_id,recipient_name,recipient_phone,created_at
 dm240726f65t8n ,Shahin Raza,01326885301,2026-07-24
,No Cn Person,01326885302,2026-07-25
DM2407BBB,,01326885303,2026-07-25
";
        $this->actingAs($owner)->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('pathao.csv', $csv)]);
        $id = DB::table('customer_imports')->value('id');
        $this->get(route('customers.history.map', $id))->assertSee('CN (courier consignment)');
        $body = ['source' => 'Pathao', 'mapping' => ['consignment_id' => 'cn', 'recipient_name' => 'name', 'recipient_phone' => 'phone', 'created_at' => 'date']];
        $this->postJson(route('customers.history.preview', $id), ['source' => 'Pathao', 'mapping' => ['consignment_id' => 'cn', 'recipient_phone' => 'phone', 'created_at' => 'date']])
            ->assertStatus(422)->assertJsonPath('message', 'Pick the Name column.');
        $this->postJson(route('customers.history.preview', $id), $body); $this->postJson(route('customers.history.run', $id), $body)->assertOk();
        $this->assertSame(['DM240726F65T8N', null], DB::table('customer_past_orders')->orderBy('id')->pluck('cn')->all()); // no CN is fine, no name is not
        $this->assertSame(1, DB::table('customer_imports')->where('id', $id)->value('result') ? json_decode(DB::table('customer_imports')->where('id', $id)->value('result'), true)['no_name'] : 0);

        $customer = DB::table('customer_past_orders')->where('cn', 'DM240726F65T8N')->value('customer_id');
        $this->get('/journey?q='.urlencode('dm240726f65t8n'))->assertRedirect(route('journey.show', $customer));
        $this->get(route('journey.show', $customer))->assertSee('CN DM240726F65T8N');
        $this->get('/order-search?by=cn&q=DM240726F65T8N')->assertSee('Imported: Pathao')->assertSee('Shahin Raza')->assertDontSee('No order found');

        // The same parcel from another file is not brought in twice.
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('again.csv', "cn,name,phone,date
DM240726F65T8N,Shahin Raza,01326885301,2026-07-24
")]);
        $again = DB::table('customer_imports')->max('id');
        $this->postJson(route('customers.history.preview', $again), ['source' => 'Other', 'mapping' => ['cn' => 'cn', 'name' => 'name', 'phone' => 'phone', 'date' => 'date']])
            ->assertJsonPath('counts.orders', 0)->assertJsonPath('counts.already', 1);
    }

    public function test_a_pathao_file_is_guessed_right_and_needs_no_source_name(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $csv = "consignment_id,merchant_name,recipient_name,recipient_phone,invoice_id,created_at
DM1AAAAA,Iqbal Store,Md Shahin Raza,01326885301,260726MQKJLAG,7/24/2026 16:27
DM1BBBBB,Iqbal Store,Antara Das,01764121879,260726MQKJLAG,7/24/2026 16:26
";
        $this->actingAs($owner)->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('1st 300.csv', $csv)]);
        $id = DB::table('customer_imports')->value('id');
        $guess = $this->get(route('customers.history.map', $id))->viewData('mapping');
        $this->assertSame(['consignment_id' => 'cn', 'merchant_name' => '', 'recipient_name' => 'name', 'recipient_phone' => 'phone', 'invoice_id' => '', 'created_at' => 'date'], $guess);
        $this->postJson(route('customers.history.preview', $id), ['source' => '', 'mapping' => $guess]); $this->postJson(route('customers.history.run', $id), ['source' => '', 'mapping' => $guess])->assertOk();
        $this->assertSame(['1st 300', '1st 300'], DB::table('customer_past_orders')->pluck('source')->all()); // two parcels, named after the file
    }

    public function test_nothing_imports_without_the_checked_mapping_and_an_import_can_be_undone(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $known = Customer::create(['name' => 'Old Customer', 'primary_phone' => '01712345678']);
        DB::table('customer_phones')->insert(['customer_id' => $known->id, 'phone' => '01712345678', 'is_primary' => true]);
        $csv = "phone,name,date
01712345678,Old Customer,2025-01-01
01812345678,New One,2025-01-02
";
        $this->actingAs($owner)->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv)]);
        $id = DB::table('customer_imports')->value('id');
        $body = ['source' => 'Sheet', 'mapping' => ['phone' => 'phone', 'name' => 'name', 'date' => 'date']];

        $this->postJson(route('customers.history.run', $id), $body)->assertStatus(422); // never checked
        $this->postJson(route('customers.history.preview', $id), $body)->assertOk();
        $this->postJson(route('customers.history.run', $id), ['source' => 'Other'] + $body)->assertStatus(422); // changed since the check
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();
        $this->assertSame(2, DB::table('customer_past_orders')->count());
        $this->get(route('customers.history.show', $id))->assertSee('Undo this import');

        $this->post(route('customers.history.undo', $id))->assertRedirect(route('customers.history.show', $id));
        $this->assertSame(0, DB::table('customer_past_orders')->count());
        $this->assertNull(Customer::where('primary_phone', '01812345678')->first()); // created by the import: gone
        $this->assertNotNull($known->fresh());                                        // was here before: stays
        $this->get(route('customers.history.show', $id))->assertSee('taken back')->assertDontSee('Undo this import');
    }

    public function test_picks_are_remembered_by_column_name_and_a_second_upload_updates_what_changed(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $first = "consignment_id,recipient_name,recipient_phone,amount_to_collect,collected_amount,created_at
DM1AAAAA,Shahin,01326885301,0,1500,2026-07-24
DM1BBBBB,Antara,01764121879,2180,2180,2026-07-24
";
        $this->actingAs($owner)->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('first.csv', $first)]);
        $id = DB::table('customer_imports')->max('id');
        // Wrong pick the first time: amount_to_collect (0 for a prepaid parcel).
        $body = ['source' => 'Pathao', 'mapping' => ['consignment_id' => 'cn', 'recipient_name' => 'name', 'recipient_phone' => 'phone', 'amount_to_collect' => 'amount', 'collected_amount' => '', 'created_at' => 'date']];
        $this->postJson(route('customers.history.preview', $id), $body);
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();
        $this->assertEquals(0, DB::table('customer_past_orders')->where('cn', 'DM1AAAAA')->value('amount'));

        // Another file with more columns: the columns seen before come back as picked (saved), the new one is guessed.
        $second = "consignment_id,merchant_name,recipient_name,recipient_phone,amount_to_collect,collected_amount,created_at
DM1AAAAA,Iqbal Store,Shahin,01326885301,0,1500,2026-07-24
DM1BBBBB,Iqbal Store,Antara,01764121879,2180,2180,2026-07-24
DM1CCCCC,Iqbal Store,Nusrat,01912345678,900,900,2026-07-25
";
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('second.csv', $second)]);
        $id2 = DB::table('customer_imports')->max('id');
        $map = $this->get(route('customers.history.map', $id2))->assertSee('saved')->viewData('mapping');
        $this->assertSame(['consignment_id' => 'cn', 'merchant_name' => '', 'recipient_name' => 'name', 'recipient_phone' => 'phone', 'amount_to_collect' => 'amount', 'collected_amount' => '', 'created_at' => 'date'], $map);

        // Fix the pick: the parcels brought in before are updated, the new one added.
        $fixed = ['source' => 'Pathao', 'mapping' => ['amount_to_collect' => '', 'collected_amount' => 'amount'] + $map];
        $this->postJson(route('customers.history.preview', $id2), $fixed)->assertJsonPath('counts.orders', 1)->assertJsonPath('counts.updated', 1)->assertJsonPath('counts.already', 1);
        $this->postJson(route('customers.history.run', $id2), $fixed)->assertOk();
        $this->assertEquals(1500, DB::table('customer_past_orders')->where('cn', 'DM1AAAAA')->value('amount'));
        $this->assertSame(3, DB::table('customer_past_orders')->count());
    }

    public function test_uploading_a_file_again_replaces_its_unfinished_draft_and_old_drafts_go(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $csv = "phone,name,date
01712345678,Someone,2025-01-01
";
        $this->actingAs($owner)->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv)]);
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('b.csv', $csv)]);
        DB::table('customer_imports')->where('file_name', 'b.csv')->update(['updated_at' => now()->subDays(2)]);
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv)]);
        // a.csv: only the newest draft; b.csv: too old, gone.
        $this->assertSame(['a.csv'], DB::table('customer_imports')->pluck('file_name')->all());
        $this->assertSame(1, DB::table('customer_imports')->count());
    }

    public function test_the_sample_file_round_trips_with_no_mapping_to_do(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $xlsx = $this->actingAs($owner)->get('/customers/history/sample')->assertOk()->assertHeader('content-disposition', 'attachment; filename="iqs-orders-sample.xlsx"')->getContent();
        $path = tempnam(sys_get_temp_dir(), 's').'.xlsx';
        file_put_contents($path, $xlsx);
        // Read back by our own reader: header and the example row.
        [$header, $rows] = app(\App\Services\Customers\HistoryImport::class)->read($path, 'xlsx');
        $this->assertSame(['Name', 'CN', 'Phone', 'Address', 'Products', 'Amount', 'Date', 'Status'], $header);
        $this->assertSame('Rahima Akter', $rows[0][0]);

        // Filled in (CSV is fine too): every column known, an empty date and status, the example row skipped for its fake phone.
        $csv = "Name,Phone,CN,Address,Products,Amount,Date,Status
Rahima Akter,01XXXXXXXXX,DM1,x,y,1,2026-07-24,Delivered
Karim Uddin,+8801712345678,,Road 1,Ajwa 1 kg,900,,
Nusrat,০১৯১২৩৪৫৬৭৮,DM2BBBBB,Feni,Honey,1200,24/07/2026,Returned
";
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('iqs-orders-sample.csv', $csv)]);
        $id = DB::table('customer_imports')->value('id');
        $view = $this->get(route('customers.history.map', $id))->assertOk()->assertSee('Sample file');
        $this->assertTrue($view->viewData('isSample'));
        $this->assertEquals(['Name' => 'name', 'Phone' => 'phone', 'CN' => 'cn', 'Address' => 'address', 'Products' => 'items', 'Amount' => 'amount', 'Date' => 'date', 'Status' => 'status'], $view->viewData('mapping'));
        $body = ['source' => '', 'mapping' => $view->viewData('mapping')];
        $this->postJson(route('customers.history.preview', $id), $body)->assertJsonPath('counts.orders', 2)->assertJsonPath('counts.bad_phone', 1)->assertJsonPath('counts.skipped', 0);
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();
        $karim = DB::table('customer_past_orders')->where('amount', 900)->first();
        $this->assertSame('delivered', $karim->status);                       // empty status
        $this->assertSame(now()->format('Y-m-d'), substr($karim->ordered_at, 0, 10)); // empty date
        $this->assertSame('01712345678', \App\Models\Customer::find($karim->customer_id)->primary_phone);
        $this->assertSame('2026-07-24', substr(DB::table('customer_past_orders')->where('cn', 'DM2BBBBB')->value('ordered_at'), 0, 10));
    }

    /** Uploads a CSV, checks and imports it with the given picks; returns the import id. */
    private function importCsv(string $name, string $csv, array $mapping, string $source = ''): int
    {
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent($name, $csv)]);
        $id = (int) DB::table('customer_imports')->max('id');
        $body = ['source' => $source, 'mapping' => $mapping];
        $this->postJson(route('customers.history.preview', $id), $body)->assertOk();
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();

        return $id;
    }

    public function test_review_fixes_merge_keyless_rows_encoding_words_dates_and_undo(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $this->actingAs($owner);
        $plain = ['phone' => 'phone', 'name' => 'name', 'date' => 'date', 'amount' => 'amount'];

        // A sheet with neither order number nor CN: the second upload is recognised, not doubled.
        $sheet = "phone,name,date,amount\n01712345678,Karim,2025-01-01,1200\n01712345678,Karim,,800\n";
        $this->importCsv('own.csv', $sheet, $plain);
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('own again.csv', $sheet)]);
        $again = (int) DB::table('customer_imports')->max('id');
        $this->postJson(route('customers.history.preview', $again), ['source' => '', 'mapping' => $plain])->assertJsonPath('counts.orders', 0)->assertJsonPath('counts.already', 2);
        $this->assertSame(2, DB::table('customer_past_orders')->count());

        // Excel "CSV (Comma delimited)" in the Windows code page: read, not a crash.
        $ansi = "phone,name,date,amount\n01812345678,Caf\xE9 \x96 Shop,2025-02-01,500\n";
        $this->importCsv('ansi.csv', $ansi, $plain);
        $this->assertSame('Café – Shop', \App\Models\Customer::where('primary_phone', '01812345678')->value('name'));

        // Pathao words and dates: "Pickup Cancelled" is cancelled, "Partial Delivery" delivered; "7/8/2026, 16:27" is 8 July.
        $h = app(\App\Services\Customers\HistoryImport::class);
        $this->assertSame(['cancelled', 'delivered', 'skip'], [$h->guessValue('status', 'Pickup Cancelled'), $h->guessValue('status', 'Partial Delivery'), $h->guessValue('status', 'Lost')]);
        $pathao = "consignment_id,recipient_name,recipient_phone,created_at\nDM1AAAAA,Shahin,1.326885301E9,\"7/8/2026, 16:27\"\nDM1BBBBB,Bad,8.80133E+12,\"7/9/2026, 10:00\"\n";
        $pid = $this->importCsv('pathao.csv', $pathao, ['consignment_id' => 'cn', 'recipient_name' => 'name', 'recipient_phone' => 'phone', 'created_at' => 'date'], 'Pathao');
        $this->assertSame('2026-07-08', substr(DB::table('customer_past_orders')->where('cn', 'DM1AAAAA')->value('ordered_at'), 0, 10));
        $this->assertSame(1, json_decode(DB::table('customer_imports')->where('id', $pid)->value('result'), true)['bad_phone']); // digits lost by Excel: not a made-up phone

        // Merging moves the imported history; the old journey link and the CN lead to the customer that is left.
        $shahin = \App\Models\Customer::where('primary_phone', '01326885301')->first();
        $keep = \App\Models\Customer::where('primary_phone', '01712345678')->first();
        app(\App\Services\Customers\CustomerService::class)->merge($keep, $shahin, $owner->id);
        $this->assertSame($keep->id, DB::table('customer_past_orders')->where('cn', 'DM1AAAAA')->value('customer_id'));
        $this->get(route('journey.show', $shahin->id))->assertRedirect(route('journey.show', $keep->id));
        $this->get('/journey?q=DM1AAAAA')->assertRedirect(route('journey.show', $keep->id));
        $this->get(route('journey.show', $keep->id))->assertSee('CN DM1AAAAA');

        // Undo puts back what an import changed on earlier parcels.
        $fixed = "consignment_id,recipient_name,recipient_phone,created_at,collected_amount\nDM1AAAAA,Shahin,01326885301,\"7/8/2026, 16:27\",1500\n";
        $fid = $this->importCsv('fixed.csv', $fixed, ['consignment_id' => 'cn', 'recipient_name' => 'name', 'recipient_phone' => 'phone', 'created_at' => 'date', 'collected_amount' => 'amount'], 'Pathao');
        $this->assertEquals(1500, DB::table('customer_past_orders')->where('cn', 'DM1AAAAA')->value('amount'));
        $this->post(route('customers.history.undo', $fid));
        $this->assertEquals(0, DB::table('customer_past_orders')->where('cn', 'DM1AAAAA')->value('amount'));

        // Two columns with one name keep apart; a run cannot happen twice.
        [$header] = $h->read(tap(tempnam(sys_get_temp_dir(), 'c'), fn ($p) => file_put_contents($p, "Phone,Phone,Name\n017,018,X\n")), 'csv');
        $this->assertSame(['Phone', 'Phone (2)', 'Name'], $header);
        $this->postJson(route('customers.history.run', $fid), ['source' => 'Pathao', 'mapping' => ['recipient_phone' => 'phone', 'recipient_name' => 'name']])->assertStatus(422);
    }

    public function test_saved_picks_never_put_one_field_on_two_columns(): void
    {
        (new CustomerSeeder)->run();
        $this->actingAs($this->owner());
        DB::table('import_templates')->insert([
            ['name' => 'Old', 'signature' => 'a', 'mapping' => json_encode(['collected_amount' => 'amount', 'phone' => 'phone']), 'values' => '{}', 'default_medium' => 'messenger', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
            ['name' => 'New', 'signature' => 'b', 'mapping' => json_encode(['amount_to_collect' => 'amount', 'name' => 'name']), 'values' => '{}', 'default_medium' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('x.csv', "phone,name,amount_to_collect,collected_amount\n01712345678,A,0,100\n")]);
        $view = $this->get(route('customers.history.map', DB::table('customer_imports')->max('id')));
        $this->assertSame(['amount_to_collect' => 'amount', 'collected_amount' => ''], array_intersect_key($view->viewData('mapping'), ['amount_to_collect' => 1, 'collected_amount' => 1]));
        $this->assertSame('messenger', $view->viewData('defaultMedium'));
    }
}
