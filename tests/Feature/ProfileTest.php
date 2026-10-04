<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_renders(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertOk()->assertSee($user->email);
    }

    public function test_name_and_phone_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', ['name' => 'Mahim', 'phone' => '01712345678'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Mahim', 'phone' => '01712345678']);
    }

    public function test_invalid_or_taken_phone_is_rejected(): void
    {
        User::factory()->create(['phone' => '01712345678']);
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', ['name' => 'X', 'phone' => '12345'])->assertSessionHasErrors('phone');
        $this->actingAs($user)->patch('/profile', ['name' => 'X', 'phone' => '01712345678'])->assertSessionHasErrors('phone');
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'wrong',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_reset_link_is_sent_only_to_active_users_with_same_reply(): void
    {
        Notification::fake();
        $active = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);

        $this->post('/forgot-password', ['email' => $active->email])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => $inactive->email])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');

        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email))->assertOk();

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])->assertRedirect('/login');

            return Hash::check('brand-new-pass', $user->fresh()->password);
        });
    }
}
