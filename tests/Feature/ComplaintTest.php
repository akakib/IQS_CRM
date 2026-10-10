<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Complaints\ComplaintService;
use App\Services\Orders\OrderService;
use App\Services\PermissionService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\OrderConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        (new RoleSeeder)->run();
        (new NotificationSeeder)->run();
        OrderStatus::forget();
        Storage::fake('local');
        Product::factory()->withVariant(900)->create(['name' => 'Figs']);

        $this->moderator = User::factory()->create(['name' => 'Mahim']);
        $this->moderator->roles()->attach(DB::table('roles')->where('system_key', 'moderator')->value('id'));
        app(PermissionService::class)->bump();

        $this->order = app(OrderService::class)->create([
            'channel' => 'messenger', 'phone' => '01712345678', 'name' => 'Karim', 'address_line' => 'Road 1',
            'items' => [['variant_id' => ProductVariant::first()->id, 'qty' => 1]],
        ], $this->moderator);
    }

    private function categoryId(string $key = 'wrong_item'): int
    {
        return (int) DB::table('status_reasons')->where('reason_type', 'complaint')->where('system_key', $key)->value('id');
    }

    public function test_opening_a_complaint_on_an_order_links_it_assigns_the_moderator_and_stores_photos(): void
    {
        $manager = $this->owner(['name' => 'Boss']);

        $this->actingAs($manager)->post('/complaints', [
            'order_no' => $this->order->order_no, 'category_id' => $this->categoryId(), 'source' => 'phone',
            'description' => 'Got almonds instead of figs',
            'photos' => [UploadedFile::fake()->image('parcel.jpg', 600, 400)],
        ])->assertRedirect();

        $c = Complaint::first();
        $this->assertSame($this->order->id, $c->order_id);
        $this->assertSame($this->order->customer_id, $c->customer_id);
        $this->assertSame('01712345678', $c->customer_phone);
        $this->assertSame($this->moderator->id, $c->assigned_to, 'defaults to the order moderator');
        $this->assertSame('packing', $c->blame_stage, 'blame stage copied from the category');
        $this->assertSame('open', $c->status);
        $this->assertNotNull($c->sla_due_at);
        $this->assertDatabaseHas('complaint_photos', ['complaint_id' => $c->id]);
        Storage::disk('local')->assertExists(DB::table('complaint_photos')->value('path'));
        $this->assertDatabaseHas('complaint_events', ['complaint_id' => $c->id, 'action' => 'opened']);
        $this->assertDatabaseHas('order_notes', ['order_id' => $this->order->id, 'note_type' => 'manual']);
        // The moderator was told.
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->moderator->id]);

        $this->actingAs($manager)->get('/complaints/'.$c->id)->assertOk()->assertSee('Got almonds instead of figs')->assertSee($this->order->order_no);
        $this->actingAs($manager)->get('/complaints/'.$c->id.'/photos/'.DB::table('complaint_photos')->value('id'))->assertOk();
    }

    public function test_a_complaint_needs_an_order_or_a_phone(): void
    {
        $this->actingAs($this->owner())->post('/complaints', ['category_id' => $this->categoryId(), 'source' => 'phone', 'description' => 'x'])
            ->assertSessionHasErrors('customer_phone');
        $this->assertSame(0, Complaint::count());
    }

    public function test_moderator_sees_only_complaints_assigned_to_them(): void
    {
        $other = User::factory()->create(['name' => 'Rina']);
        $svc = app(ComplaintService::class);
        $mine = $svc->open(['order_id' => $this->order->id, 'category_id' => $this->categoryId(), 'source' => 'phone', 'description' => 'mine'], [], $this->moderator);
        $theirs = $svc->open(['customer_phone' => '01811111111', 'customer_name' => 'Someone', 'category_id' => $this->categoryId('other'), 'source' => 'whatsapp', 'description' => 'theirs', 'assigned_to' => $other->id], [], $this->owner());

        $this->actingAs($this->moderator)->get('/complaints?tab=all')->assertOk()->assertSee('#'.$mine->id)->assertDontSee('Someone');
        $this->actingAs($this->moderator)->get('/complaints/'.$theirs->id)->assertForbidden();
        $this->actingAs($this->moderator)->post('/complaints/'.$theirs->id.'/notes', ['body' => 'hi'])->assertForbidden();
    }

    public function test_resolving_names_the_stage_and_defaults_the_blamed_person_then_can_be_reopened(): void
    {
        $c = app(ComplaintService::class)->open(['order_id' => $this->order->id, 'category_id' => $this->categoryId('wrong_amount'), 'source' => 'phone', 'description' => 'charged more'], [], $this->owner());
        $this->assertSame('sales', $c->blame_stage);

        $this->actingAs($this->moderator)->post('/complaints/'.$c->id.'/resolve', ['resolution' => 'solved', 'blame_stage' => 'sales', 'note' => 'Explained the delivery charge'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('resolved', $c->status);
        $this->assertSame('solved', $c->resolution);
        $this->assertSame($this->moderator->id, $c->blamed_user_id, 'sales stage = the order moderator');
        $this->assertDatabaseHas('complaint_events', ['complaint_id' => $c->id, 'action' => 'resolved']);

        // Resolving twice is refused; reopening restarts the clock.
        $this->actingAs($this->moderator)->post('/complaints/'.$c->id.'/resolve', ['resolution' => 'solved', 'blame_stage' => 'none'])->assertSessionHasErrors('status');
        $this->actingAs($this->moderator)->post('/complaints/'.$c->id.'/reopen', ['why' => 'customer called again'])->assertRedirect();
        $this->assertSame('open', $c->refresh()->status);
        $this->assertNull($c->resolution);
    }

    public function test_refunded_resolution_needs_an_approved_refund(): void
    {
        $c = app(ComplaintService::class)->open(['order_id' => $this->order->id, 'category_id' => $this->categoryId(), 'source' => 'phone', 'description' => 'x'], [], $this->owner());
        $this->actingAs($this->owner())->post('/complaints/'.$c->id.'/resolve', ['resolution' => 'refunded', 'blame_stage' => 'packing'])->assertSessionHasErrors('resolution');
        $this->assertSame('open', $c->refresh()->status);
    }

    public function test_overdue_complaints_escalate_to_managers_once(): void
    {
        $manager = $this->owner(['name' => 'Boss']);
        $c = app(ComplaintService::class)->open(['order_id' => $this->order->id, 'category_id' => $this->categoryId(), 'source' => 'phone', 'description' => 'late'], [], $manager);
        DB::table('app_notifications')->delete();

        $this->artisan('complaints:escalate')->assertSuccessful();
        $this->assertNull($c->refresh()->escalated_at, 'not due yet');

        $this->travel(25)->hours();
        $this->artisan('complaints:escalate')->assertSuccessful();
        $this->assertNotNull($c->refresh()->escalated_at);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $manager->id]);
        $this->assertDatabaseHas('complaint_events', ['complaint_id' => $c->id, 'action' => 'escalated']);
        $n = DB::table('app_notifications')->count();
        $this->artisan('complaints:escalate');
        $this->assertSame($n, DB::table('app_notifications')->count(), 'escalated only once');

        $this->actingAs($manager)->get('/complaints')->assertOk()->assertSee('Overdue');
        $this->actingAs($manager)->get('/dashboard')->assertOk()->assertSee('Open complaints');
    }

    public function test_complaints_are_listed_on_the_order_page_and_categories_are_editable_reasons(): void
    {
        $owner = $this->owner();
        app(ComplaintService::class)->open(['order_id' => $this->order->id, 'category_id' => $this->categoryId('damaged'), 'source' => 'rider', 'description' => 'leaking'], [], $owner);
        $this->actingAs($owner)->get('/orders/'.$this->order->id)->assertOk()->assertSee('Damaged or leaking');

        $this->actingAs($owner)->post('/settings/reasons', ['reason_type' => 'complaint', 'label_en' => 'Expired product', 'blame_stage' => 'packing'])->assertRedirect();
        $this->actingAs($owner)->get('/complaints/create')->assertOk()->assertSee('Expired product');
    }
}
