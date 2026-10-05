<?php

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CourierAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_saves_steadfast_keys_encrypted_edits_them_and_checks_the_connection(): void
    {
        $this->actingAs($this->owner());

        $this->post('/settings/couriers', ['name' => 'Main Steadfast', 'api_key' => 'APIKEY-1234567890', 'secret_key' => 'SECRET-abcdefghij', 'is_default' => 1])
            ->assertSessionHas('success');
        $account = CourierAccount::firstOrFail();
        $this->assertTrue($account->is_default);
        $this->assertSame('APIKEY-1234567890', $account->api_key);
        $this->assertNotSame('APIKEY-1234567890', DB::table('courier_accounts')->value('api_key')); // stored encrypted

        // Shown masked only.
        $this->get('/settings/couriers')->assertOk()->assertSee('APIK…7890')->assertDontSee('APIKEY-1234567890')->assertDontSee('SECRET-abcdefghij');

        // Empty key on edit keeps the saved one; a typed one replaces it.
        $this->put("/settings/couriers/{$account->id}", ['name' => 'Renamed', 'api_key' => '', 'secret_key' => 'SECRET-new-000000'])->assertSessionHas('success');
        $account->refresh();
        $this->assertSame('Renamed', $account->name);
        $this->assertSame('APIKEY-1234567890', $account->api_key);
        $this->assertSame('SECRET-new-000000', $account->secret_key);

        // The activity log never holds the keys.
        $this->assertStringNotContainsString('SECRET-new', (string) DB::table('activity_log')->orderByDesc('id')->value('after'));

        // Check connection: a balance call with the saved keys.
        Http::fake(['*/get_balance' => Http::response(['status' => 200, 'current_balance' => 1520.5])]);
        $this->post("/settings/couriers/{$account->id}/check")->assertSessionHas('success', 'Connected. Balance ৳1,520.50');
        Http::assertSent(fn ($r) => $r->hasHeader('Api-Key', 'APIKEY-1234567890') && $r->hasHeader('Secret-Key', 'SECRET-new-000000'));
    }

    public function test_staff_cannot_see_or_change_courier_keys(): void
    {
        $staff = User::factory()->create();
        $staff->roles()->attach($this->role(['orders.view' => 'own', 'orders.edit'], [], 'Moderator')->id);
        $this->actingAs($staff)->get('/settings/couriers')->assertForbidden();
        $this->post('/settings/couriers', ['name' => 'x', 'api_key' => 'a', 'secret_key' => 'b'])->assertForbidden();
    }
}
