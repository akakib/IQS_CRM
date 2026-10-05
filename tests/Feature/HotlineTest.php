<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Courier\BookingService;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HotlineTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Order $order;

    private string $cn;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new RoleSeeder)->run();
        (new NotificationSeeder)->run();
        OrderStatus::forget();
        Product::factory()->withVariant(900)->create(['name' => 'Figs']);

        $this->agent = User::factory()->create(['name' => 'Mahim']);
        $this->agent->roles()->attach(DB::table('roles')->where('system_key', 'moderator')->value('id'));
        app(\App\Services\PermissionService::class)->bump();

        $order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]],
        ], $this->agent);
        $sm = app(OrderStateMachine::class);
        $sm->transition($order, 'record_verified', null, 'rule');
        $sm->transition($order, 'confirmed', $this->agent);
        app(BookingService::class)->book([$order->id], $this->owner(['name' => 'Desk']));
        $this->order = $order->fresh();
        $this->cn = DB::table('shipments')->where('order_id', $order->id)->value('consignment_id');
    }

    public function test_hotline_finds_by_cn_and_phone_and_shows_cod_moderator_items(): void
    {
        $this->actingAs($this->owner());

        $this->get('/hotline?q='.$this->cn)->assertOk()->assertSee($this->order->order_no)->assertSee('Figs')->assertSee('Mahim');
        $this->get('/hotline?q=+8801712345678')->assertSee($this->order->order_no);
    }

    public function test_bigger_issue_goes_to_the_moderator_with_a_timer_and_escalates_when_late(): void
    {
        $this->actingAs($this->owner());
        $this->post("/hotline/{$this->order->id}/issue", ['issue_type' => 'partial', 'rider_phone' => '01811111111', 'note' => 'Wants only one item'])
            ->assertSessionHas('success');

        $issue = DB::table('delivery_issues')->first();
        $this->assertSame($this->agent->id, $issue->assigned_to);
        $this->assertSame('01811111111', $issue->rider_phone);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->agent->id, 'priority' => 'urgent']);

        $this->actingAs($this->agent)->get('/issues')->assertOk()->assertSee('Wants only one item');

        $this->travel(2)->hours();
        Artisan::call('issues:escalate');
        $this->assertNotNull(DB::table('delivery_issues')->value('escalated_at'));
        Artisan::call('issues:escalate');
        $this->assertSame(1, DB::table('order_notes')->where('body', 'like', '%sent to managers%')->count()); // once
    }

    public function test_owner_closes_the_issue_and_urgent_highlight_ends(): void
    {
        $this->actingAs($this->owner())->post("/hotline/{$this->order->id}/issue", ['issue_type' => 'no_answer']);
        $issue = DB::table('delivery_issues')->value('id');

        $this->actingAs($this->agent)->post("/issues/{$issue}/resolve", ['resolution' => 'delivered', 'note' => 'Called, received'])->assertSessionHas('success');

        $this->assertSame('delivered', DB::table('delivery_issues')->value('resolution'));
        $this->assertSame(0, DB::table('app_notifications')->where('subject_id', $this->order->id)->where('priority', 'urgent')->whereNull('acted_at')->count());

        $other = User::factory()->create();
        $other->roles()->attach(DB::table('roles')->where('system_key', 'moderator')->value('id'));
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($this->owner())->post("/hotline/{$this->order->id}/issue", ['issue_type' => 'address']);
        $second = DB::table('delivery_issues')->whereNull('resolved_at')->value('id');
        $this->actingAs($other)->post("/issues/{$second}/resolve", ['resolution' => 'solved'])->assertForbidden();
    }

    public function test_small_issue_is_saved_on_the_timeline(): void
    {
        $this->actingAs($this->owner())->post("/hotline/{$this->order->id}/solved", ['note' => 'Customer coming down in 5 minutes'])->assertSessionHas('success');

        $this->assertDatabaseHas('order_notes', ['order_id' => $this->order->id, 'note_type' => 'rider']);
    }
}
