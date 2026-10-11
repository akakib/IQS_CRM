<?php

namespace Tests\Feature;

use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Someone leaves: work not called yet goes back to New, work already talked about goes to one person. */
class StaffHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivating_hands_their_work_over(): void
    {
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new RoleSeeder)->run();
        (new NotificationSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(500)->create(['name' => 'Dates']);
        $moderator = DB::table('roles')->where('system_key', 'moderator')->value('id');
        [$rima, $mahim, $jesi] = [User::factory()->create(['name' => 'Rima']), User::factory()->create(['name' => 'Mahim']), User::factory()->create(['name' => 'Jesi'])];
        foreach ([$rima, $mahim, $jesi] as $u) {
            $u->roles()->attach($moderator);
        }
        app(\App\Services\PermissionService::class)->bump();
        $owner = $this->owner(['name' => 'Boss']);

        $make = fn (User $u, string $phone) => app(OrderService::class)->create(['channel' => 'messenger', 'phone' => $phone, 'name' => 'Karim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]]], $u);
        $fresh = $make($rima, '01712345671');
        DB::table('orders')->where('id', $fresh->id)->update(['moderator_id' => $rima->id]);
        $talked = $make($rima, '01712345672');
        DB::table('orders')->where('id', $talked->id)->update(['moderator_id' => $rima->id]);
        $sm = app(OrderStateMachine::class);
        $sm->transition($talked->fresh(), 'record_verified', null, 'rule');
        $sm->transition($talked->fresh(), 'no_answer', $rima);
        $busy = $make($mahim, '01712345673'); // Mahim already has one open: Jesi is the least loaded
        DB::table('orders')->where('id', $busy->id)->update(['moderator_id' => $mahim->id]);
        $issue = DB::table('delivery_issues')->insertGetId(['order_id' => $talked->id, 'issue_type' => 'no_answer', 'assigned_to' => $rima->id, 'sla_due_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner);
        $w = $this->getJson(route('users.work', $rima))->assertOk()->json();
        $this->assertSame([1, 1, 1], [$w['fresh'], $w['talking'], $w['issues']]);
        $this->assertSame(['Jesi', 'Mahim'], array_column($w['people'], 'name'));

        $this->patch(route('users.status', $rima))->assertSessionHasErrors('give_to'); // work held: someone must take it
        $this->assertTrue($rima->fresh()->is_active);

        $this->patch(route('users.status', $rima), ['give_to' => $jesi->id])->assertSessionHas('success');
        $this->assertFalse($rima->fresh()->is_active);
        $this->assertNull(DB::table('orders')->where('id', $fresh->id)->value('moderator_id'));       // back to New
        $this->assertSame($jesi->id, (int) DB::table('orders')->where('id', $talked->id)->value('moderator_id'));
        $this->assertSame($jesi->id, (int) DB::table('delivery_issues')->where('id', $issue)->value('assigned_to'));
        $this->assertSame($mahim->id, (int) DB::table('orders')->where('id', $busy->id)->value('moderator_id'));
        $this->assertDatabaseHas('order_notes', ['order_id' => $fresh->id, 'body' => 'Rima no longer works here: waiting in New again.']);

        $this->get('/users')->assertOk()->assertSee('Deactivate and hand over');
    }
}
