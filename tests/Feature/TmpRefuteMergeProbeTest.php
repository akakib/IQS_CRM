<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\Customers\CustomerService;
use Database\Seeders\CustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TmpRefuteMergeProbeTest extends TestCase
{
    use RefreshDatabase;

    private function upload(string $name, string $csv): int
    {
        $this->post('/customers/history', ['file' => UploadedFile::fake()->createWithContent($name, $csv)]);

        return (int) DB::table('customer_imports')->max('id');
    }

    public function test_probe_merge_after_import(): void
    {
        (new CustomerSeeder)->run();
        $owner = $this->owner();
        $this->actingAs($owner);
        $csv = "consignment_id,recipient_phone,recipient_name,created_at,collected_amount,transfer_status\nDM240726F65T8N,01326885301,Shahin,\"7/24/2026, 16:27\",500,Delivered\nDM240726F65T8P,01326885301,Shahin,\"7/25/2026, 16:27\",700,Delivered\n";
        $body = ['source' => 'Pathao', 'mapping' => ['consignment_id' => 'cn', 'recipient_phone' => 'phone', 'recipient_name' => 'name', 'created_at' => 'date', 'collected_amount' => 'amount', 'transfer_status' => 'status']];
        $id = $this->upload('p.csv', $csv);
        $this->postJson(route('customers.history.preview', $id), $body)->assertOk();
        $this->postJson(route('customers.history.run', $id), $body)->assertOk();

        $x = Customer::where('primary_phone', '01326885301')->first();
        fwrite(STDERR, "\nX id {$x->id}, past rows for X: ".DB::table('customer_past_orders')->where('customer_id', $x->id)->count()."\n");
        $xJourney = $this->get(route('journey.show', $x));
        fwrite(STDERR, 'X journey before merge: status '.$xJourney->status().' orders '.$xJourney->viewData('stats')['orders']."\n");

        $y = app(CustomerService::class)->save(new Customer, ['name' => 'Y', 'primary_phone' => '01712345678'], [], [], $owner->id);
        $this->post("/customers/{$y->id}/merge", ['duplicate_phone' => '01326885301'])->assertSessionHas('success');

        fwrite(STDERR, 'past rows still on X after merge: '.DB::table('customer_past_orders')->where('customer_id', $x->id)->count().', on Y: '.DB::table('customer_past_orders')->where('customer_id', $y->id)->count()."\n");

        $r = $this->get(route('journey.show', $y));
        fwrite(STDERR, 'Y journey: status '.$r->status().' orders '.$r->viewData('stats')['orders'].' sold '.$r->viewData('stats')['sold']."\n");

        $r = $this->get(route('journey.index', ['q' => 'DM240726F65T8N']));
        fwrite(STDERR, 'journey search by CN: status '.$r->status().' redirected to '.($r->headers->get('Location') ?? 'nowhere').' body has "No customer found": '.(str_contains($r->getContent(), 'No customer found') ? 'yes' : 'no')."\n");

        $r = $this->get("/journey/{$x->id}");
        fwrite(STDERR, '/journey/X: status '.$r->status()."\n");

        $r = $this->get(route('orders.search', ['q' => 'DM240726F65T8N']));
        fwrite(STDERR, 'order search by CN: status '.$r->status().' past cards '.$r->viewData('past')->count().' link target customer_id '.($r->viewData('past')->first()->customer_id ?? 'none')."\n");

        $id2 = $this->upload('p.csv', $csv);
        $r = $this->postJson(route('customers.history.preview', $id2), $body);
        fwrite(STDERR, 'reimport preview counts: '.json_encode($r->json('counts')).' message: '.json_encode($r->json('message'))."\n");
    }
}
