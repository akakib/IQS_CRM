<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Courier\BookingService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** COD from courier: a statement marks parcels paid; what is missing or short stays visible. */
class CodPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_statement_matches_parcels_and_flags_the_short_and_the_unpaid(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new NotificationSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(1000)->create(['name' => 'Figs']);
        $owner = $this->owner();
        $this->actingAs($owner);

        $cns = [];
        foreach (['01712345671', '01712345672', '01712345673'] as $phone) {
            $o = app(OrderService::class)->create(['channel' => 'messenger', 'phone' => $phone, 'name' => 'Karim', 'address_line' => 'Road 1',
                'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]]], $owner);
            $sm = app(OrderStateMachine::class);
            $sm->transition($o->fresh(), 'record_verified', null, 'rule');
            $sm->transition($o->fresh(), 'confirmed', $owner);
            if (! DB::table('shipments')->where('order_id', $o->id)->exists()) {
                app(BookingService::class)->book([$o->id], $owner);
            }
            DB::table('shipments')->where('order_id', $o->id)->update(['final_at' => now()->subDays(9), 'collected_amount' => 1060, 'delivery_charge' => 60]);
            $cns[] = DB::table('shipments')->where('order_id', $o->id)->value('consignment_id');
        }

        $this->get(route('cod.index'))->assertOk()->assertSee('Still owed by the courier:')->assertSee('৳3,000')->assertSee('9 days waiting');

        $csv = "\xEF\xBB\xBFConsignment ID,Invoice,Collected Amount,Delivery Charge\n{$cns[0]},X,1060,60\n{$cns[1]},X,\"1,000\",60\n999999999,X,500,60\n,,3620,180\n";
        $this->post(route('cod.upload'), ['file' => UploadedFile::fake()->createWithContent('pay.csv', $csv), 'paid_on' => today()->toDateString(), 'reference' => 'PAY-1'])
            ->assertRedirect(route('cod.index', ['tab' => 'mismatch']))->assertSessionHas('error');

        $p = DB::table('courier_payouts')->first();
        $this->assertSame([1, 1, 1], [(int) $p->matched, (int) $p->mismatched, (int) $p->unknown]);
        $this->get(route('cod.index', ['tab' => 'mismatch']))->assertOk()->assertSee('short ৳60');
        $this->get(route('cod.index'))->assertSee('৳1,000'); // one parcel still unpaid: 1060 less 60
        $this->get(route('cod.index', ['tab' => 'statements']))->assertSee('PAY-1');

        $this->post(route('cod.upload'), ['file' => UploadedFile::fake()->createWithContent('bad.csv', "Name,Amount\nA,1\n"), 'paid_on' => today()->toDateString()])
            ->assertSessionHasErrors('file');
    }
}
