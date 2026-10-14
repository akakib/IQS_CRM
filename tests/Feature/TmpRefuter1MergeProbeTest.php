<?php

namespace Tests\Feature;

use App\Models\Customer;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TmpRefuter1MergeProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_merge_drops_imported_history(): void
    {
        (new CustomerSeeder)->run();
        $this->actingAs($this->owner());
        $csv = "consignment_id,recipient_name,recipient_phone,created_at,amount_to_collect,transfer_status\nDM240726F65T8N,Md Shahin Raza,01326885301,7/24/2026 16:27,1500,Delivered\n";
        $body = ['source' => 'Pathao', 'mapping' => ['consignment_id' => 'cn', 'recipient_name' => 'name', 'recipient_phone' => 'phone', 'created_at' => 'date', 'amount_to_collect' => 'amount', 'transfer_status' => 'status']];
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)]);
        $id = (int) DB::table('customer_imports')->max('id');
        $this->postJson(route('customers.history.preview', $id), $body)->assertOk();
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();

        $x = Customer::where('primary_phone', '01326885301')->firstOrFail();
        $this->assertSame(1, DB::table('customer_past_orders')->where('customer_id', $x->id)->count());

        $y = Customer::create(['name' => 'Shahin Raza', 'primary_phone' => '01712345678']);
        DB::table('customer_phones')->insert(['customer_id' => $y->id, 'phone' => '01712345678', 'is_primary' => true]);
        $this->post("/customers/{$y->id}/merge", ['duplicate_phone' => '01326885301'])->assertSessionHas('success');

        $out = [];
        $out['past_customer_id_after_merge'] = DB::table('customer_past_orders')->value('customer_id');
        $out['x_id'] = $x->id;
        $out['y_id'] = $y->id;
        $out['y_journey_orders'] = $this->get(route('journey.show', $y))->assertOk()->viewData('stats')['orders'];
        $j = $this->get('/journey?q=DM240726F65T8N');
        $out['journey_cn_status'] = $j->status();
        $out['journey_cn_no_customer'] = str_contains($j->getContent(), 'No customer found');
        $s = $this->get('/order-search?by=cn&q=DM240726F65T8N')->assertOk();
        $out['search_card_shown'] = str_contains($s->getContent(), 'Imported: Pathao');
        $out['search_link_to_x'] = str_contains($s->getContent(), '/journey/'.$x->id.'"');
        $out['journey_x_status'] = $this->get('/journey/'.$x->id)->status();
        $out['phone_search_redirect'] = $this->get('/journey?q=01326885301')->headers->get('Location');

        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('p2.csv', $csv)]);
        $id2 = (int) DB::table('customer_imports')->max('id');
        $out['reimport_counts'] = $this->postJson(route('customers.history.preview', $id2), $body)->json('counts');

        fwrite(STDERR, "\nPROBE: ".json_encode($out, JSON_PRETTY_PRINT)."\n");
        $this->assertTrue(true);
    }
}
