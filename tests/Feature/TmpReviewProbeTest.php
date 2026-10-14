<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Customers\CustomerService;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TmpReviewProbeTest extends TestCase
{
    use RefreshDatabase;

    private function upload(string $name, string $csv): int
    {
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent($name, $csv)]);

        return (int) DB::table('customer_imports')->max('id');
    }

    public function test_probe_row_keyed_reimport_doubles(): void
    {
        (new CustomerSeeder)->run();
        $this->actingAs($this->owner());
        $csv = "phone,name,date,amount\n01712345678,Someone,2025-01-01,500\n01712345678,Someone,2025-02-01,700\n";
        $body = ['source' => 'Sheet', 'mapping' => ['phone' => 'phone', 'name' => 'name', 'date' => 'date', 'amount' => 'amount']];
        $id = $this->upload('a.csv', $csv);
        $this->postJson(route('customers.history.preview', $id), $body);
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();
        $id2 = $this->upload('a.csv', $csv);
        $r = $this->postJson(route('customers.history.preview', $id2), $body);
        fwrite(STDERR, "\nROW-KEYED second preview counts: ".json_encode($r->json('counts'))."\n");
        $this->postJson(route('customers.history.run', $id2), $body)->assertOk();
        fwrite(STDERR, 'ROW-KEYED past orders after 2 imports: '.DB::table('customer_past_orders')->count()."\n");
        $c = Customer::where('primary_phone', '01712345678')->first();
        $this->get(route('journey.show', $c))->assertOk();
        fwrite(STDERR, 'ROW-KEYED journey sold: '.$this->get(route('journey.show', $c))->viewData('stats')['sold']."\n");
    }

    public function test_probe_known_counts_orders_not_customers(): void
    {
        (new CustomerSeeder)->run();
        $this->actingAs($this->owner());
        $known = Customer::create(['name' => 'K', 'primary_phone' => '01712345678']);
        DB::table('customer_phones')->insert(['customer_id' => $known->id, 'phone' => '01712345678', 'is_primary' => true]);
        $csv = "phone,name,date,cn\n01712345678,K,2025-01-01,CN00001\n01712345678,K,2025-01-02,CN00002\n01712345678,K,2025-01-03,CN00003\n01812345678,N,2025-01-04,CN00004\n01812345678,N,2025-01-05,CN00005\n";
        $body = ['source' => 'Sheet', 'mapping' => ['phone' => 'phone', 'name' => 'name', 'date' => 'date', 'cn' => 'cn']];
        $id = $this->upload('k.csv', $csv);
        $r = $this->postJson(route('customers.history.preview', $id), $body);
        fwrite(STDERR, "\nKNOWN/NEW counts (1 known customer w/ 3 orders, 1 new w/ 2): ".json_encode($r->json('counts'))."\n");
    }

    public function test_probe_primary_phone_collision_after_merge_and_edit(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $this->actingAs($owner);
        $svc = app(CustomerService::class);
        $a = $svc->save(new Customer, ['name' => 'A', 'primary_phone' => '01712345678'], [], [], $owner->id);
        $b = $svc->save(new Customer, ['name' => 'B', 'primary_phone' => '01812345678'], [], [], $owner->id);
        $svc->merge($a, $b, $owner->id);
        // Edit A: keep only its own number -> B's old number leaves customer_phones, but B (soft-deleted) still holds it in customers.primary_phone.
        $svc->save($a->fresh(), ['name' => 'A', 'primary_phone' => '01712345678'], [], [], $owner->id);
        fwrite(STDERR, "\nphones rows for 01812345678: ".DB::table('customer_phones')->where('phone', '01812345678')->count().', customers rows: '.DB::table('customers')->where('primary_phone', '01812345678')->count()."\n");
        $csv = "phone,name,date\n01812345678,B Again,2025-01-01\n";
        $body = ['source' => 'Sheet', 'mapping' => ['phone' => 'phone', 'name' => 'name', 'date' => 'date']];
        $id = $this->upload('m.csv', $csv);
        $r = $this->postJson(route('customers.history.preview', $id), $body);
        fwrite(STDERR, 'MERGE preview counts: '.json_encode($r->json('counts'))."\n");
        $run = $this->withoutExceptionHandling()->postJson(route('customers.history.run', $id), $body);
        fwrite(STDERR, 'MERGE run status: '.$run->status()."\n");
    }

    public function test_probe_saved_mapping_from_two_templates_doubles_amount(): void
    {
        (new CustomerSeeder)->run();
        $this->actingAs($this->owner());
        // Older template: a file with collected_amount only.
        $id = $this->upload('old.csv', "recipient_phone,recipient_name,created_at,collected_amount\n01712345678,X,2025-01-01,100\n");
        $b1 = ['source' => 'Pathao', 'mapping' => ['recipient_phone' => 'phone', 'recipient_name' => 'name', 'created_at' => 'date', 'collected_amount' => 'amount']];
        $this->postJson(route('customers.history.preview', $id), $b1); $this->postJson(route('customers.history.run', $id), $b1)->assertOk();
        DB::table('import_templates')->update(['updated_at' => now()->subDay()]);
        // Newer template: a file with amount_to_collect only.
        $id = $this->upload('new.csv', "recipient_phone,recipient_name,created_at,amount_to_collect\n01812345678,Y,2025-01-01,100\n");
        $b2 = ['source' => 'Pathao', 'mapping' => ['recipient_phone' => 'phone', 'recipient_name' => 'name', 'created_at' => 'date', 'amount_to_collect' => 'amount']];
        $this->postJson(route('customers.history.preview', $id), $b2); $this->postJson(route('customers.history.run', $id), $b2)->assertOk();
        // A file with both columns.
        $id = $this->upload('both.csv', "recipient_phone,recipient_name,created_at,amount_to_collect,collected_amount\n01912345678,Z,2025-01-01,0,900\n");
        $map = $this->get(route('customers.history.map', $id))->viewData('mapping');
        fwrite(STDERR, "\nSAVED mapping offered for a file with both amount columns: ".json_encode($map)."\n");
        $r = $this->postJson(route('customers.history.preview', $id), ['source' => 'Pathao', 'mapping' => $map]);
        fwrite(STDERR, 'Check with that mapping -> '.$r->status().' '.json_encode($r->json('message'))."\n");
    }

    public function test_probe_cn_not_stored_when_matched_by_order_no(): void
    {
        (new CustomerSeeder)->run();
        $this->actingAs($this->owner());
        $id = $this->upload('w1.csv', "Order Number,Billing Phone,Billing First Name,Order Date,Order Total Amount\n5521,01712345678,R,2025-02-12,890\n");
        $b1 = ['source' => 'WooCommerce', 'mapping' => ['Order Number' => 'order_no', 'Billing Phone' => 'phone', 'Billing First Name' => 'name', 'Order Date' => 'date', 'Order Total Amount' => 'amount']];
        $this->postJson(route('customers.history.preview', $id), $b1); $this->postJson(route('customers.history.run', $id), $b1)->assertOk();
        $id = $this->upload('w2.csv', "Order Number,Billing Phone,Billing First Name,Order Date,Order Total Amount,Tracking Code\n5521,01712345678,R,2025-02-12,890,DM240726F65T8N\n");
        $b2 = $b1; $b2['mapping']['Tracking Code'] = 'cn';
        $r = $this->postJson(route('customers.history.preview', $id), $b2);
        fwrite(STDERR, "\nCN-ON-UPDATE preview counts: ".json_encode($r->json('counts'))."\n");
        $this->postJson(route('customers.history.run', $id), $b2)->assertOk();
        fwrite(STDERR, 'CN-ON-UPDATE stored cn: '.json_encode(DB::table('customer_past_orders')->pluck('cn')->all())."\n");
        // Then the courier file brings the same parcel by CN.
        $id = $this->upload('p.csv', "consignment_id,recipient_phone,recipient_name,created_at,collected_amount\nDM240726F65T8N,01712345678,R,2025-02-13,890\n");
        $b3 = ['source' => 'Pathao', 'mapping' => ['consignment_id' => 'cn', 'recipient_phone' => 'phone', 'recipient_name' => 'name', 'created_at' => 'date', 'collected_amount' => 'amount']];
        $r = $this->postJson(route('customers.history.preview', $id), $b3);
        fwrite(STDERR, 'CN-ON-UPDATE courier file preview counts: '.json_encode($r->json('counts'))."\n");
        $this->postJson(route('customers.history.run', $id), $b3)->assertOk();
        fwrite(STDERR, 'CN-ON-UPDATE rows for the one parcel: '.DB::table('customer_past_orders')->count()."\n");
    }

    public function test_probe_month_first_file_with_small_days(): void
    {
        $this->actingAs($this->owner());
        $csv = "recipient_phone,recipient_name,created_at\n01326885301,A,\"7/8/2026, 16:27\"\n01926885301,B,\"7/9/2026, 09:05\"\n";
        $id = $this->upload('p.csv', $csv);
        $r = $this->postJson(route('customers.history.preview', $id), ['source' => 'Pathao', 'mapping' => ['recipient_phone' => 'phone', 'recipient_name' => 'name', 'created_at' => 'date']]);
        fwrite(STDERR, "\nMONTH-FIRST small-day dates parsed as: ".json_encode(array_column($r->json('sample'), 'ordered_at'))."\n");
    }

    public function test_probe_duplicate_cn_in_file_under_two_order_numbers(): void
    {
        (new CustomerSeeder)->run();
        $this->actingAs($this->owner());
        $csv = "Order Number,Billing Phone,Billing First Name,Order Date,Tracking Code\n1,01712345678,R,2025-02-12,DM1AAAAA\n2,01712345678,R,2025-02-13,DM1AAAAA\n";
        $b = ['source' => 'W', 'mapping' => ['Order Number' => 'order_no', 'Billing Phone' => 'phone', 'Billing First Name' => 'name', 'Order Date' => 'date', 'Tracking Code' => 'cn']];
        $id = $this->upload('d.csv', $csv);
        $r = $this->postJson(route('customers.history.preview', $id), $b);
        fwrite(STDERR, "\nDUP-CN preview counts: ".json_encode($r->json('counts'))."\n");
        $this->postJson(route('customers.history.run', $id), $b)->assertOk();
        fwrite(STDERR, 'DUP-CN rows stored: '.DB::table('customer_past_orders')->count().' result: '.DB::table('customer_imports')->where('id', $id)->value('result')."\n");
    }

    public function test_probe_invalid_utf8_csv(): void
    {
        $this->actingAs($this->owner());
        $r = $this->withoutExceptionHandling()->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('ansi.csv', "phone,name,date\n01712345678,Caf\xE9 Rahim,2025-01-01\n")]);
        fwrite(STDERR, "\nANSI upload status: ".$r->status()."\n");
    }
}
