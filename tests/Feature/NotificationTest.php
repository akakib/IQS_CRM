<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationDelivery;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\NotificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('notifications:sync');
    }

    private function rule(string $type, array $attrs): void
    {
        DB::table('notification_rules')->insert($attrs + [
            'type_id' => DB::table('notification_types')->where('system_key', $type)->value('id'),
            'channel_in_app' => true, 'channel_telegram' => false, 'channel_sms' => false, 'is_active' => true,
        ]);
    }

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    public function test_rule_for_a_role_notifies_every_user_with_that_role(): void
    {
        $role = $this->role([], [], 'Moderator');
        [$a, $b, $outsider] = User::factory()->count(3)->create();
        $a->roles()->attach($role->id);
        $b->roles()->attach($role->id);
        $inactive = User::factory()->create(['is_active' => false]);
        $inactive->roles()->attach($role->id);
        $this->rule('new_order', ['target' => 'role', 'role_id' => $role->id]);

        $sent = $this->service()->send('new_order', 'New order #1001');

        $this->assertSame(2, $sent);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $a->id)->count());
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $b->id)->count());
        $this->assertSame(0, DB::table('app_notifications')->whereIn('user_id', [$outsider->id, $inactive->id])->count());
    }

    public function test_marking_read_updates_the_unread_count(): void
    {
        $user = User::factory()->create();
        $this->service()->send('system_test', 'One', null, ['user_ids' => [$user->id]]);
        $this->service()->send('system_test', 'Two', null, ['user_ids' => [$user->id]]);
        $this->actingAs($user);

        $this->getJson('/notifications/count')->assertJson(['unread' => 2]);

        $id = DB::table('app_notifications')->where('user_id', $user->id)->value('id');
        $this->get("/notifications/{$id}/open")->assertRedirect();
        $this->getJson('/notifications/count')->assertJson(['unread' => 1]);

        $this->postJson('/notifications/read-all')->assertOk();
        $this->getJson('/notifications/count')->assertJson(['unread' => 0]);
    }

    public function test_feed_shows_only_own_items_and_filters_urgent(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->service()->send('system_test', 'Mine', null, ['user_ids' => [$me->id]]);
        $this->service()->send('delivery_issue', 'Rider waiting', null, ['user_ids' => [$me->id]]);
        $this->service()->send('system_test', 'Not mine', null, ['user_ids' => [$other->id]]);

        $this->actingAs($me);
        $this->getJson('/notifications/feed')->assertJsonCount(2)->assertJsonMissing(['title' => 'Not mine']);
        $this->getJson('/notifications/feed?filter=urgent')->assertJsonCount(1)->assertJsonFragment(['title' => 'Rider waiting', 'urgent' => true]);
        $this->getJson('/notifications/count')->assertJson(['urgent' => 1]);

        $foreign = DB::table('app_notifications')->where('user_id', $other->id)->value('id');
        $this->get("/notifications/{$foreign}/open")->assertNotFound();
    }

    public function test_urgent_stays_highlighted_until_acted(): void
    {
        $user = User::factory()->create();
        $this->service()->send('delivery_issue', 'Partial delivery', null, ['user_ids' => [$user->id], 'subject' => ['order', 55]]);
        $this->actingAs($user);

        $this->postJson('/notifications/read-all');
        $this->getJson('/notifications/count')->assertJson(['unread' => 0]);
        $this->getJson('/notifications/feed?filter=urgent')->assertJsonCount(1);

        $this->service()->markActed('order', 55);
        $this->getJson('/notifications/feed?filter=urgent')->assertJsonCount(0);
    }

    public function test_repeated_events_are_grouped(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->service()->send('order_needs_repack', "Order #{$i} needs repack", null, ['user_ids' => [$user->id], 'group_key' => 'repack']);
        }

        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $user->id)->count());
        $this->assertSame(3, (int) DB::table('app_notifications')->where('user_id', $user->id)->value('group_count'));
    }

    public function test_order_owner_target_and_telegram_delivery_is_queued(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['telegram_user_id' => '12345']);
        $this->rule('delivery_issue', ['target' => 'order_owner', 'channel_telegram' => true]);

        $this->service()->send('delivery_issue', 'Customer not answering', null, ['order_owner_id' => $owner->id]);

        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'telegram', 'status' => 'queued']);
        Queue::assertPushed(SendNotificationDelivery::class);
    }

    public function test_muted_type_is_skipped_unless_urgent(): void
    {
        $user = User::factory()->create();
        foreach (['new_order', 'delivery_issue'] as $key) {
            DB::table('user_notification_prefs')->insert([
                'user_id' => $user->id,
                'type_id' => DB::table('notification_types')->where('system_key', $key)->value('id'),
                'mute_until' => now()->addHour(),
            ]);
        }

        $this->assertSame(0, $this->service()->send('new_order', 'x', null, ['user_ids' => [$user->id]]));
        $this->assertSame(1, $this->service()->send('delivery_issue', 'y', null, ['user_ids' => [$user->id]]));
    }

    public function test_settings_matrix_adds_rules_and_needs_permission(): void
    {
        (new NotificationSeeder)->run();
        $owner = $this->owner();
        $this->actingAs($owner);
        $role = $this->role([], [], 'Packers');
        $type = DB::table('notification_types')->where('system_key', 'stock_issue_reported')->value('id');

        $this->get('/settings/notifications')->assertOk()->assertSee('Packer could not find an item');
        $this->post('/settings/notifications/rules', ['type_id' => $type, 'target' => 'role', 'role_id' => $role->id, 'channel_in_app' => 1])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('notification_rules', ['type_id' => $type, 'role_id' => $role->id]);
        $this->assertDatabaseHas('activity_log', ['action' => 'notification_rule.created']);

        $this->post('/settings/notifications/test')->assertSessionHas('success');
        $this->assertDatabaseHas('app_notifications', ['user_id' => $owner->id, 'title' => 'Test notification']);

        $this->actingAs(User::factory()->create())->get('/settings/notifications')->assertForbidden();
    }
}
