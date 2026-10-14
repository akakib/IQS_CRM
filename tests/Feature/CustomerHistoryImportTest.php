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

        $this->postJson(route('customers.history.run', $id), $body)->assertOk()->assertJsonPath('url', route('customers.history.show', $id));
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
        $this->get(route('customers.history.map', $second))->assertSee('Matched the saved template WooCommerce');
        $this->postJson(route('customers.history.preview', $second), $body)->assertJsonPath('counts.orders', 0)->assertJsonPath('counts.already', 2);
        $this->postJson(route('customers.history.run', $id), $body)->assertStatus(422);

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
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();
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
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();
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
}
