<?php

namespace Tests\Feature;

use App\Models\ChatChannel;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatModeTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;

    private User $mahim;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00')); // a Monday, on shift
        (new CatalogSeeder)->run();
        (new CustomerSeeder)->run();
        (new OrderConfigSeeder)->run();
        OrderStatus::forget();
        $this->boss = User::factory()->create(['name' => 'Boss']);
        $this->boss->roles()->attach($this->role(['orders.view' => 'all', 'orders.edit', 'orders.reassign', 'settings.view', 'settings.edit', 'staff.view', 'staff.edit'], [], 'Manager')->id);
        $this->mahim = User::factory()->create(['name' => 'Mahim']);
        $this->mahim->roles()->attach($this->role(['orders.view' => 'own', 'orders.create', 'orders.edit', 'orders.take', 'products.view', 'customers.view'], [], 'Moderator')->id);
        app(\App\Services\PermissionService::class)->bump();
        Product::factory()->withVariant(600, ['sku' => 'DATE-500'])->create(['name' => 'Dates']);
        $this->variant = ProductVariant::firstWhere('sku', 'DATE-500');
    }

    private function reason(string $type, string $key): int
    {
        return (int) DB::table('status_reasons')->where('reason_type', $type)->where('system_key', $key)->value('id');
    }

    public function test_the_admin_sets_channels_and_people_count_only_their_own_with_reasons(): void
    {
        // The admin adds channels and gives one to Mahim on the Staff page.
        $this->actingAs($this->boss)->post('/settings/chat-channels', ['name' => 'WhatsApp 2', 'type' => 'whatsapp'])->assertRedirect();
        $this->post('/settings/chat-channels', ['name' => 'Page 1 comments', 'type' => 'comments']);
        [$wa, $comments] = ChatChannel::orderBy('id')->get()->all();
        $this->get('/users/'.$this->mahim->id.'/edit')->assertSee('Chat channels')->assertSee('Page 1 comments');
        $this->put('/users/'.$this->mahim->id, ['name' => 'Mahim', 'email' => $this->mahim->email, 'chat_channels_shown' => 1, 'chat_channels' => [$wa->id]])->assertRedirect();
        $this->assertSame([$wa->id], $this->mahim->chatChannels()->pluck('chat_channels.id')->all());

        // Or from the channel itself: Access shows who has it; the popup sets many people at once (inactive staff are skipped).
        $this->get('/settings/chat-channels')->assertOk()->assertSee('Access')->assertSeeInOrder(['WhatsApp 2', 'Mahim']);
        $gone = User::factory()->create(['is_active' => false]);
        $this->post('/settings/chat-channels/'.$comments->id.'/people', ['users' => [$this->mahim->id, $this->boss->id, $gone->id]])->assertRedirect('/settings/chat-channels');
        $this->assertEqualsCanonicalizing([$this->mahim->id, $this->boss->id], $comments->users()->pluck('users.id')->all());
        $this->post('/settings/chat-channels/'.$comments->id.'/people', [])->assertRedirect();
        $this->assertSame(0, $comments->users()->count());
        $this->actingAs($this->mahim)->post('/settings/chat-channels/'.$comments->id.'/people', ['users' => [$this->mahim->id]])->assertForbidden();
        $this->actingAs($this->boss);

        // Mahim sees Chat beside Break, and only his channel in it.
        $this->actingAs($this->mahim)->get('/dashboard')->assertSee('Communication off', false);
        $this->getJson('/chat')->assertOk()->assertJsonCount(1, 'channels')->assertJsonPath('channels.0.name', 'WhatsApp 2')->assertJsonPath('on', false);

        // Counting turns chat mode on. Taking one back or "no order" needs a reason; someone else's channel is refused.
        foreach (range(1, 3) as $i) {
            $this->postJson('/chat/events', ['channel_id' => $wa->id, 'kind' => 'message'])->assertOk();
        }
        $this->getJson('/chat')->assertJsonPath('on', true)->assertJsonPath('channels.0.messages', 3);
        $this->postJson('/chat/events', ['channel_id' => $wa->id, 'kind' => 'undo'])->assertStatus(422)->assertJsonValidationErrors('reason_id');
        $this->postJson('/chat/events', ['channel_id' => $wa->id, 'kind' => 'undo', 'reason_id' => $this->reason('chat_undo', 'wrong_tap')])->assertJsonPath('channels.0.messages', 2);
        $this->postJson('/chat/events', ['channel_id' => $wa->id, 'kind' => 'no_order', 'reason_id' => $this->reason('chat_undo', 'wrong_tap')])->assertStatus(422); // a lost-chat reason is needed
        $this->postJson('/chat/events', ['channel_id' => $wa->id, 'kind' => 'no_order', 'reason_id' => $this->reason('chat_lost', 'price_high')])->assertJsonPath('channels.0.no_order', 1);
        $this->postJson('/chat/events', ['channel_id' => $comments->id, 'kind' => 'message'])->assertStatus(422)->assertJsonValidationErrors('channel');

        // An order from that chat: the shortcut opens the form on that channel; the order keeps it.
        $this->get('/orders/create?chat_channel='.$wa->id)->assertOk()->assertSee('From which chat');
        $this->post('/orders', [
            'channel' => 'whatsapp', 'chat_channel_id' => $wa->id, 'phone' => '01812345678', 'name' => 'Rahim', 'address_line' => 'House 9',
            'zone_id' => DB::table('delivery_zones')->where('system_key', 'inside_dhaka')->value('id'),
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]],
        ])->assertRedirect();
        $this->assertSame($wa->id, Order::first()->chat_channel_id);
        $this->post('/orders', ['channel' => 'messenger', 'chat_channel_id' => $comments->id, 'phone' => '01812345679', 'name' => 'X', 'address_line' => 'Y',
            'items' => [['variant_id' => $this->variant->id, 'qty' => 1]]])->assertSessionHasErrors('chat_channel_id');
        $this->getJson('/chat')->assertJsonPath('channels.0.orders', 1);

        // 30 minutes of chat is work, not free time; Activity tells what happened in it.
        $this->travel(30)->minutes();
        $this->postJson('/chat/stop')->assertJsonPath('on', false);
        $activity = app(\App\Services\Reports\PersonActivity::class)->day($this->mahim->id, today());
        $chat = collect($activity['entries'])->firstWhere('kind', 'chat');
        $this->assertSame('Communication 30 min · 2 messages answered · 1 order · no order: Price too high', $chat['text']);
        $this->assertSame('10:30', $chat['until']->format('H:i'));
        $this->assertSame(0, collect($activity['entries'])->where('kind', 'free')->filter(fn ($e) => $e['at']->format('H:i') === '10:00')->count());
        $this->actingAs($this->boss)->get('/work-time')->assertOk()->assertSeeInOrder(['Mahim', '30 min', '2 msg · 1 orders']);

        // Chats report: per channel and per person, with the reasons for no order. 1 order + 1 no order = 50%.
        $this->get('/chat-report')->assertOk()
            ->assertSeeInOrder(['Why chats ended with no order', 'Price too high', '1 · 100%'])
            ->assertSeeInOrder(['By channel', 'WhatsApp 2', '2', '1', '৳', '1', '50%', 'Price too high 1'])
            ->assertSeeInOrder(['By person', 'Mahim', '2', '1'])
            ->assertDontSee('Page 1 comments'); // nothing counted there
        $this->actingAs($this->mahim)->get('/chat-report')->assertForbidden();
    }

    public function test_with_empty_hands_and_nothing_waiting_the_desk_points_to_chat(): void
    {
        $wa = ChatChannel::create(['name' => 'WhatsApp 1', 'type' => 'whatsapp']);
        $this->mahim->chatChannels()->attach($wa->id);

        $this->actingAs($this->mahim)->get('/desk')->assertOk()->assertSee('No website orders now. Answer chats and comments meanwhile');
        $this->postJson('/chat/start')->assertJsonPath('on', true);
        $this->get('/desk')->assertDontSee('No website orders now. Answer chats and comments meanwhile');

        // Without a channel there is no Chat button and no hint.
        $rima = User::factory()->create();
        $rima->roles()->attach($this->mahim->roles()->first()->id);
        $this->actingAs($rima)->get('/desk')->assertOk()->assertDontSee('No website orders now')->assertDontSee('Communication off', false);
        $this->postJson('/chat/start')->assertStatus(422);
    }
}
