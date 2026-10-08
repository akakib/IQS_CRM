<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiderCallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rider_call_is_found_by_cn_checked_with_the_customer_and_counted_per_rider(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        Product::factory()->withVariant(600, ['sku' => 'D-1'])->create(['name' => 'Dates']);
        $nishan = User::factory()->create(['name' => 'Nishan']);
        $nishan->roles()->attach($this->role(['orders.view' => 'all', 'hotline.view', 'hotline.create'], [], 'Hotline')->id);
        $boss = User::factory()->create(['name' => 'Boss']);
        $boss->roles()->attach($this->role(['orders.view' => 'all', 'orders.reassign'], [], 'Manager')->id);
        $mod = User::factory()->create(['name' => 'Mahim']);
        $mod->roles()->attach($this->role(['orders.view' => 'own', 'orders.edit', 'orders.take'], [], 'Moderator')->id);
        app(\App\Services\PermissionService::class)->bump();

        $order = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '01712345678', 'name' => 'Rahim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::firstWhere('sku', 'D-1')->id, 'qty' => 1]],
        ], null, 'webhook');
        $shipment = DB::table('shipments')->insertGetId(['order_id' => $order->id, 'courier' => 'steadfast', 'consignment_id' => '305555858', 'cod_amount' => 700, 'courier_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->where('id', $order->id)->update(['active_shipment_id' => $shipment, 'moderator_id' => $mod->id, 'status_id' => OrderStatus::idFor('in_transit')]);
        DB::table('order_notes')->insert(['order_id' => $order->id, 'note_type' => 'courier', 'body' => 'Courier: Customer did not pick up', 'created_at' => now()]);

        // The Communication button, and the parcel found by its CN with what Steadfast last said.
        $this->actingAs($nishan)->get('/dashboard')->assertSee('Communication');
        $this->getJson('/rider-calls/find?q=305555858')->assertJson(['found' => true, 'order_no' => $order->order_no, 'cn' => '305555858', 'courier_note' => 'Courier: Customer did not pick up', 'earlier_calls' => 0]);
        $this->getJson('/rider-calls/find?q=999999999')->assertJson(['found' => false]);

        // Rider Rafiq says the customer does not answer; the customer did answer Nishan: not true. Rider tries again.
        $this->postJson('/rider-calls', ['order_id' => $order->id, 'rider_name' => 'Rafiq', 'rider_phone' => '01911111111', 'claim' => 'no_answer', 'action' => 'retry'])
            ->assertStatus(422)->assertJsonValidationErrors('verdict');
        $this->postJson('/rider-calls', ['order_id' => $order->id, 'rider_name' => 'Rafiq', 'rider_phone' => '01911111111', 'claim' => 'no_answer', 'verdict' => 'false', 'action' => 'retry'])
            ->assertOk()->assertJson(['today' => 1]);
        $this->assertDatabaseHas('riders', ['name' => 'Rafiq', 'phone' => '01911111111']);
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'rider', 'body' => 'Rider call Rafiq: Customer not answering · checked with the customer: Not true · Rider tries again']);
        $this->assertNotNull(app(\App\Services\Work\ChatService::class)->openSession($nishan->id)); // counts as work

        // The second call: true this time, sent to the person on the order as a delivery issue.
        $this->postJson('/rider-calls', ['order_id' => $order->id, 'rider_name' => 'Rafiq', 'claim' => 'later', 'verdict' => 'true', 'action' => 'moderator', 'note' => 'Friday'])->assertOk();
        $issue = DB::table('delivery_issues')->where('order_id', $order->id)->first();
        $this->assertSame('hold', $issue->issue_type);
        $this->assertSame($mod->id, (int) $issue->assigned_to);
        $this->assertSame($issue->id, (int) DB::table('rider_calls')->whereNotNull('delivery_issue_id')->value('delivery_issue_id'));
        $this->getJson('/rider-calls/find?q=305555858')->assertJson(['earlier_calls' => 2, 'open_issues' => 1]);

        // The Riders report: Rafiq, 2 calls on 1 parcel, 1 of 2 checked not true (50%).
        $this->actingAs($boss)->get('/riders-report')->assertOk()->assertSeeInOrder(['Rafiq', '01911111111', '2', '1', '1', '50%'])->assertSee('Nishan');
        // Without the hotline: no rider calls.
        $this->actingAs($mod)->getJson('/rider-calls/find?q=305555858')->assertForbidden();

        // Or access to the Rider line channel: rider calls in Communication, but it is not a chat.
        $line = \App\Models\ChatChannel::create(['name' => 'Rider line 01700', 'type' => 'rider', 'is_active' => true]);
        $line->users()->attach($mod->id);
        $mod = \App\Models\User::find($mod->id); // a fresh request
        $this->actingAs($mod)->getJson('/rider-calls/find?q=305555858')->assertOk()->assertJson(['found' => true]);
        $this->get('/dashboard')->assertSee('Communication')->assertSee('Rider name');
        $this->assertTrue(app(\App\Services\Work\ChatService::class)->channelsFor($mod)->isEmpty());
        $line->update(['is_active' => false]);
        $this->actingAs(\App\Models\User::find($mod->id))->getJson('/rider-calls/find?q=305555858')->assertForbidden();
    }
}
