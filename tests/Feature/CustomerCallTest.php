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
        $this->patchJson('/customer-calls/'.$id, ['outcome' => 'answered', 'minutes' => 2, 'seconds' => 5, 'recording_url' => 'https://drive.google.com/file/d/abc/view', 'note' => 'Wants it Friday'])
            ->assertOk()->assertJson(['message' => 'Call saved.']);
        $this->assertDatabaseHas('customer_calls', ['id' => $id, 'user_id' => $mod->id, 'phone' => '01712345678', 'outcome' => 'answered', 'duration_seconds' => 125]);
        $this->assertDatabaseHas('order_notes', ['order_id' => $order->id, 'note_type' => 'call', 'body' => 'Called (2:05): Answered · Wants it Friday']);

        // Only the caller can finish their call; the next tap is a new call.
        $this->actingAs($other)->patchJson('/customer-calls/'.$id, ['outcome' => 'answered'])->assertNotFound();
        $this->actingAs($mod)->postJson('/customer-calls', ['order_id' => $order->id])->assertOk();
        $this->assertSame(2, DB::table('customer_calls')->count());
    }
}
