<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class OrderExportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private ProductVariant $variant;

    private int $phone = 1712000100;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(600, ['sku' => 'DATE-1'])->create(['name' => 'খেজুর']);
        $this->variant = ProductVariant::firstWhere('sku', 'DATE-1');
        $this->manager = User::factory()->create(['name' => 'Boss']);
        $this->manager->roles()->attach($this->role(['orders.view' => 'all', 'orders.export', 'orders.reassign'], [], 'Manager')->id);
        app(\App\Services\PermissionService::class)->bump();
    }

    private function order(?string $status = null, int $daysAgo = 0): Order
    {
        $o = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '0'.($this->phone++), 'name' => 'Customer '.$this->phone, 'address_line' => 'Road 1',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 2]],
        ], null, 'webhook');
        DB::table('orders')->where('id', $o->id)->update(array_filter([
            'status_id' => $status ? OrderStatus::idFor($status) : null, 'created_at' => now()->subDays($daysAgo),
        ]));

        return $o->fresh();
    }

    private function range(int $days = 30): string
    {
        return 'from='.today()->subDays($days - 1)->toDateString().'&to='.today()->toDateString();
    }

    public function test_managers_export_the_chosen_orders_as_an_excel_table_and_a_print_page(): void
    {
        $a = $this->order();
        $held = $this->order('hold');
        $old = $this->order(null, 40);

        // Staff without the permission: no button, no data.
        $staff = User::factory()->create();
        $staff->roles()->attach($this->role(['orders.view' => 'own', 'orders.edit', 'orders.take'], [], 'Moderator')->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($staff)->getJson('/orders/export/count?'.$this->range())->assertForbidden();
        $this->get('/orders')->assertDontSee('Export orders');

        // The panel counts first: 30 days = the two new ones; one stage = just that one.
        $this->actingAs($this->manager)->get('/orders/activity')->assertSee('Export orders');
        $this->get('/orders')->assertSee('Export orders');
        $this->getJson('/orders/export/count?'.$this->range())->assertOk()->assertJson(['orders' => 2, 'total' => (float) $a->grand_total + (float) $held->grand_total]);
        $this->getJson('/orders/export/count?'.$this->range().'&stages[]=hold')->assertJson(['orders' => 1]);
        $this->getJson('/orders/export/count?'.$this->range(60))->assertJson(['orders' => 3]);
        $this->getJson('/orders/export/count?from=2026-01-01&to=2026-06-30')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson('/orders/export/count?'.$this->range().'&stages[]=nonsense')->assertStatus(422);

        // Excel: a real .xlsx with a header row and one row per order, Bangla intact, amounts as numbers.
        $response = $this->get('/orders/export/excel?'.$this->range().'&stages[]=hold');
        $response->assertOk()->assertDownload('orders_'.today()->subDays(29)->toDateString().'_to_'.today()->toDateString().'_hold.xlsx');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<t xml:space="preserve">Order</t>', $sheet);
        $this->assertStringContainsString($held->order_no, $sheet);
        $this->assertStringNotContainsString($a->order_no, $sheet);
        $this->assertStringContainsString('খেজুর', $sheet);
        $this->assertStringContainsString('<v>'.(float) $held->grand_total.'</v>', $sheet);

        // Print: the same table on an A4 page, with what was chosen and the totals on top.
        $this->get('/orders/export/print?'.$this->range())->assertOk()
            ->assertSee($a->order_no)->assertSee($held->order_no)->assertDontSee($old->order_no)
            ->assertSee('Stage: All stages')->assertSee('Person: Everyone')->assertSee('খেজুর');
        $this->assertSame(2, DB::table('activity_log')->where('action', 'orders.exported')->count());
    }

    public function test_a_role_that_hides_customer_contact_exports_without_it(): void
    {
        $o = $this->order();
        $clerk = User::factory()->create();
        $clerk->roles()->attach($this->role(['orders.view' => 'all', 'orders.export'], ['customer_contact'], 'Clerk')->id);
        app(\App\Services\PermissionService::class)->bump();

        $this->actingAs($clerk)->get('/orders/export/print?'.$this->range())->assertOk()
            ->assertSee($o->order_no)->assertDontSee($o->ship_phone)->assertDontSee('Road 1');
    }
}
