<?php

namespace Tests\Feature;

use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TmpReviewProbe2Test extends TestCase
{
    use RefreshDatabase;

    public function test_probe_ansi_next_steps(): void
    {
        $this->actingAs($this->owner());
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('ansi.csv', "phone,name,date\n01712345678,Caf\xE9 Rahim,2025-01-01\n")]);
        $row = DB::table('customer_imports')->first();
        fwrite(STDERR, "\nANSI stored columns json: ".var_export($row->columns, true).' | file bytes: '.strlen((string) Storage::disk('local')->get($row->path))."\n");
        $map = $this->get(route('customers.history.map', $row->id));
        fwrite(STDERR, 'ANSI map page status: '.$map->status()."\n");
        $prev = $this->postJson(route('customers.history.preview', $row->id), ['source' => 'S', 'mapping' => ['phone' => 'phone', 'name' => 'name', 'date' => 'date']]);
        fwrite(STDERR, 'ANSI preview status: '.$prev->status().' '.substr((string) $prev->json('message'), 0, 120)."\n");
    }

    public function test_probe_big_pathao_file_timing(): void
    {
        (new CustomerSeeder)->run();
        $this->actingAs($this->owner());
        $header = 'consignment_id,merchant_name,recipient_name,recipient_phone,recipient_zone,recipient_address,amount_to_collect,collected_amount,delivery_fee,cod_fee,final_fee,transfer_status,current_hub,pickup_hub,delivery_hub,Zone,payment_status,invoice_id,payment_date,created_at,sorted_at,delivered_at,item_type,measured_weight,quantity';
        $lines = [$header];
        $statuses = ['Delivered', 'Delivered', 'Delivered', 'Return', 'Paid Return', 'Exchange', 'Partial Delivery', 'Lost'];
        for ($i = 0; $i < 13860; $i++) {
            $phone = '1' . str_pad((string) (300000000 + ($i % 7000)), 9, '0', STR_PAD_LEFT); // ~7000 distinct phones, as a number like Excel gives
            $d = 1 + ($i % 28); $m = 1 + intdiv($i, 1200) % 12;
            $lines[] = sprintf('DM%06dX%04d,Iqbal Store,"Md Shahin Raza %d",%s,Uttara Sector 4,"House 69, Road 07, Sector 4, Uttara, Dhaka.",%d,%d,110,0,100,%s,Uttara,Chittagong-Halishahar,Uttara,ISD,Invoice paid,260726MQKJLAG,46230,"%d/%d/2026, 16:27","7/24/2026, 20:40","7/26/2026, 16:19",Parcel,500,1',
                $i, $i, $i, $phone, 1200 + $i % 900, 1200 + $i % 900, $statuses[$i % 8], $m, $d);
        }
        $csv = implode("\n", $lines) . "\n";
        $t = microtime(true);
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('life.csv', $csv)]);
        $id = (int) DB::table('customer_imports')->max('id');
        fwrite(STDERR, sprintf("\nBIG store: %.1fs peak %dMB\n", microtime(true) - $t, memory_get_peak_usage(true) / 1048576));
        $mapping = ['consignment_id' => 'cn', 'recipient_name' => 'name', 'recipient_phone' => 'phone', 'recipient_address' => 'address', 'collected_amount' => 'amount', 'transfer_status' => 'status', 'created_at' => 'date', 'quantity' => 'qty'];
        $values = ['status' => ['Delivered' => 'delivered', 'Return' => 'returned', 'Paid Return' => 'returned', 'Exchange' => 'skip', 'Partial Delivery' => 'delivered', 'Lost' => 'skip']];
        $body = ['source' => 'Iqbal Store Life time data (3)', 'default_medium' => 'other', 'mapping' => $mapping, 'values' => $values];
        $t = microtime(true);
        $r = $this->postJson(route('customers.history.preview', $id), $body);
        fwrite(STDERR, sprintf("BIG preview: %.1fs peak %dMB counts %s\n", microtime(true) - $t, memory_get_peak_usage(true) / 1048576, json_encode($r->json('counts'))));
        $t = microtime(true);
        DB::enableQueryLog();
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();
        fwrite(STDERR, sprintf("BIG run: %.1fs peak %dMB queries %d customers %d past %d\n", microtime(true) - $t, memory_get_peak_usage(true) / 1048576, count(DB::getQueryLog()), DB::table('customers')->count(), DB::table('customer_past_orders')->count()));
        DB::flushQueryLog();
        // Same file again with another amount column: every parcel changes.
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent('life2.csv', $csv)]);
        $id2 = (int) DB::table('customer_imports')->max('id');
        $body2 = $body; $body2['mapping']['collected_amount'] = ''; $body2['mapping']['delivery_fee'] = 'amount';
        $t = microtime(true);
        $r = $this->postJson(route('customers.history.preview', $id2), $body2);
        fwrite(STDERR, sprintf("BIG preview 2: %.1fs counts %s\n", microtime(true) - $t, json_encode($r->json('counts'))));
        $t = microtime(true);
        $this->postJson(route('customers.history.run', $id2), $body2)->assertOk();
        fwrite(STDERR, sprintf("BIG run 2 (all updated): %.1fs queries %d peak %dMB\n", microtime(true) - $t, count(DB::getQueryLog()), memory_get_peak_usage(true) / 1048576));
    }
}
