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

class CustomerCallTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_call_from_the_order_is_logged_with_how_it_went_and_the_recording(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        Artisan::call('notifications:sync');
        OrderStatus::forget();
        Product::factory()->withVariant(600, ['sku' => 'D-1'])->create(['name' => 'Dates']);
        $mod = User::factory()->create(['name' => 'Mahim']);
        $mod->roles()->attach($this->role(['orders.view' => 'own', 'orders.edit', 'orders.take'], [], 'Moderator')->id);
        $other = User::factory()->create(['name' => 'Other']);
        $other->roles()->attach($this->role(['orders.view' => 'own', 'orders.edit'], [], 'Moderator 2')->id);
        app(\App\Services\PermissionService::class)->bump();

        $order = app(OrderService::class)->create([
            'channel' => 'web', 'phone' => '01712345678', 'name' => 'Rahim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::firstWhere('sku', 'D-1')->id, 'qty' => 1]],
        ], null, 'webhook');
        DB::table('orders')->where('id', $order->id)->update(['moderator_id' => $mod->id, 'status_id' => OrderStatus::idFor('record_verified')]);

        // Someone else cannot log a call on an order they cannot see.
        $this->actingAs($other)->postJson('/customer-calls', ['order_id' => $order->id])->assertNotFound();

        // Tap to call: logged; a second tap a moment later is the same call.
        $id = $this->actingAs($mod)->postJson('/customer-calls', ['order_id' => $order->id])->assertOk()->json('id');
        $this->postJson('/customer-calls', ['order_id' => $order->id])->assertJson(['id' => $id]);
        $this->assertSame(1, DB::table('customer_calls')->count());

        // After the call.
        $this->patchJson('/customer-calls/'.$id, ['outcome' => 'maybe'])->assertStatus(422);
        $this->patchJson('/customer-calls/'.$id, ['outcome' => 'answered', 'satisfaction' => 33])->assertStatus(422);
        $this->patchJson('/customer-calls/'.$id, ['outcome' => 'answered', 'satisfaction' => 75, 'minutes' => 2, 'seconds' => 5, 'recording_url' => 'https://drive.google.com/file/d/abc/view', 'note' => 'Wants it Friday'])
            ->assertOk()->assertJson(['message' => 'Call saved.']);
        $this->assertDatabaseHas('customer_calls', ['id' => $id, 'user_id' => $mod->id, 'phone' => '01712345678', 'outcome' => 'answered', 'duration_seconds' => 125, 'satisfaction' => 75]);
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'call', 'body' => 'Called (2:05): Talked · Wants it Friday']);

        // Only the caller can finish their call; the next tap is a new call.
        $this->actingAs($other)->patchJson('/customer-calls/'.$id, ['outcome' => 'answered'])->assertNotFound();
        $this->actingAs($mod)->postJson('/customer-calls', ['order_id' => $order->id])->assertOk();
        $this->assertSame(2, DB::table('customer_calls')->count());

        // A customer phones in. Only people with Communication (a channel or the hotline) log it.
        $this->getJson('/customer-calls/recent')->assertForbidden();
        $this->getJson('/customer-calls/find?q=01712345678')->assertForbidden();
        $wa = \App\Models\ChatChannel::create(['name' => 'WhatsApp 1', 'type' => 'whatsapp', 'is_active' => true]);
        $wa->users()->attach($mod->id);
        $this->getJson('/dashboard')->assertOk();
        // Who may be calling: the latest orders they may see, one row a number, tap to open.
        $this->getJson('/customer-calls/recent')->assertOk()->assertJsonPath('people.0.phone', '01712345678')->assertJsonPath('people.0.name', 'Rahim')->assertJsonCount(1, 'people');
        $this->getJson('/customer-calls/find?q='.urlencode('+880 1712-345678'))->assertOk()
            ->assertJson(['phone' => '01712345678', 'name' => 'Rahim'])->assertJsonPath('orders.0.order_no', $order->order_no);
        $this->postJson('/customer-calls/incoming', ['phone' => '01712345678', 'order_id' => $order->id, 'reason' => 'complaint', 'minutes' => 1, 'seconds' => 0, 'note' => 'Box was wet'])
            ->assertOk()->assertJson(['today' => 1, 'new_order_url' => null]);
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'call', 'body' => 'Customer called (1:00): Complaint · Box was wet']);
        $this->assertNotNull(app(\App\Services\Work\ChatService::class)->openSession($mod->id)); // counts as work

        // A new number that wants to order: saved on the number; the reply offers the order form with it filled in.
        $this->postJson('/customer-calls/incoming', ['phone' => '01999999999', 'reason' => 'nope'])->assertStatus(422);
        $this->postJson('/customer-calls/incoming', ['phone' => '019999', 'reason' => 'new_order'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/customer-calls/incoming', ['phone' => '01999999999', 'reason' => 'new_order'])->assertOk()->assertJson(['today' => 2, 'new_order_url' => null]); // no orders.create
        $this->assertDatabaseHas('customer_calls', ['phone' => '01999999999', 'direction' => 'in', 'order_id' => null, 'outcome' => 'new_order']);
        $this->assertDatabaseMissing('customers', ['primary_phone' => '01999999999']); // nothing given: no empty customer
        $this->getJson('/customer-calls/find?q=01999999999')->assertJson(['known' => false, 'name' => null]);

        // A new caller who gives a name and address becomes a customer, once; the next call knows them.
        $this->postJson('/customer-calls/incoming', ['phone' => '01888888888', 'reason' => 'new_order', 'name' => 'Karim', 'address' => 'House 5, Agrabad'])->assertOk();
        $karim = \App\Models\Customer::firstWhere('primary_phone', '01888888888');
        $this->assertSame(['Karim', $mod->id, 'House 5, Agrabad'], [$karim->name, $karim->created_by, $karim->addresses()->value('address_line')]);
        $this->postJson('/customer-calls/incoming', ['phone' => '01888888888', 'reason' => 'other', 'name' => 'Someone else'])->assertOk();
        $this->assertSame(1, \App\Models\Customer::where('primary_phone', '01888888888')->count());
        $this->assertSame('Karim', $karim->fresh()->name);
        $this->getJson('/customer-calls/find?q=01888888888')->assertJson(['known' => true, 'name' => 'Karim']);

        // I call a customer from Communication: what happened (and how happy) instead of why.
        $this->postJson('/customer-calls/incoming', ['direction' => 'out', 'phone' => '01712345678', 'reason' => 'complaint'])->assertStatus(422)->assertJsonValidationErrors('outcome');
        $this->postJson('/customer-calls/incoming', ['direction' => 'out', 'phone' => '01712345678', 'order_id' => $order->id, 'outcome' => 'answered', 'satisfaction' => 100, 'minutes' => 0, 'seconds' => 40])
            ->assertOk();
        $this->assertDatabaseHas('customer_calls', ['phone' => '01712345678', 'direction' => 'out', 'outcome' => 'answered', 'satisfaction' => 100, 'duration_seconds' => 40]);
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'call', 'body' => 'Called (0:40): Talked']);

        // The Communication report: Chats, Customer calls and Rider calls as tabs; calls by person and one by one.
        $boss = User::factory()->create(['name' => 'Boss']);
        $boss->roles()->attach($this->role(['orders.view' => 'all', 'orders.reassign'], [], 'Manager')->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($boss)->get('/calls-report')->assertOk()->assertSee('Customer calls')->assertSee('Rider calls')
            ->assertSeeInOrder(['Mahim', '2', '1', '2'])->assertSee('Box was wet')->assertSee('01999999999');
        $this->get('/chat-report')->assertOk()->assertSee('Customer calls');
        $this->get('/riders-report')->assertOk()->assertSee('Customer calls');
        $this->actingAs($mod)->get('/calls-report')->assertForbidden();
    }
}
