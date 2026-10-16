<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Doing a thing where you already are: people on a role, a customer's orders. */
class ShortcutsTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_are_given_a_role_from_the_role_and_the_owner_role_is_left_alone(): void
    {
        (new RoleSeeder)->run();
        $owner = $this->owner(['name' => 'Boss']);
        $moderator = Role::firstWhere('system_key', 'moderator');
        [$a, $b] = [User::factory()->create(['name' => 'Mahim']), User::factory()->create(['name' => 'Rima'])];
        $gone = User::factory()->create(['is_active' => false]);
        // Rima already has it for a while (temporary): that stays as it is.
        DB::table('user_roles')->insert(['user_id' => $b->id, 'role_id' => $moderator->id, 'expires_at' => now()->addDays(3), 'reason' => 'cover', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->get('/roles')->assertOk()->assertSee('People with '.$moderator->name);
        $this->post('/roles/'.$moderator->id.'/people', ['users' => [$a->id, $gone->id]])->assertSessionHas('success');
        $this->assertSame([$a->id], DB::table('user_roles')->where('role_id', $moderator->id)->whereNull('expires_at')->pluck('user_id')->all());
        $this->assertSame(1, DB::table('user_roles')->where('role_id', $moderator->id)->where('user_id', $b->id)->whereNotNull('expires_at')->count());

        $this->post('/roles/'.$moderator->id.'/people', ['users' => []])->assertSessionHas('success');
        $this->assertSame(0, DB::table('user_roles')->where('role_id', $moderator->id)->whereNull('expires_at')->count());
        $this->post('/roles/'.Role::firstWhere('system_key', 'owner')->id.'/people', ['users' => [$a->id]])->assertForbidden();
    }

    public function test_the_customer_page_lists_their_orders_and_starts_a_new_one(): void
    {
        (new \Database\Seeders\CatalogSeeder)->run();
        (new \Database\Seeders\CustomerSeeder)->run();
        (new \Database\Seeders\OrderConfigSeeder)->run();
        \App\Models\OrderStatus::forget();
        $owner = $this->owner(['name' => 'Boss']);
        \App\Models\Product::factory()->withVariant(600, ['sku' => 'D-1'])->create(['name' => 'Dates']);
        $order = app(\App\Services\Orders\OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'House 1',
            'items' => [['variant_id' => \App\Models\ProductVariant::firstWhere('sku', 'D-1')->id, 'qty' => 1]],
        ], $owner);

        $this->actingAs($owner)->get('/customers/'.$order->customer_id)->assertOk()
            ->assertSee($order->order_no)->assertSee('New order')->assertSee('Overview')->assertSee('Journey')->assertSee('01712345678', false);
    }
}
