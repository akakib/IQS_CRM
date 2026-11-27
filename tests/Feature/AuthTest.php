<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/locations')->assertRedirect('/login');
    }

    public function test_staff_land_on_order_management_and_the_owner_on_the_dashboard(): void
    {
        $staff = User::factory()->create(['password' => 'secret-pass']);
        $staff->roles()->attach($this->role(['orders.view' => 'own', 'orders.edit' => 'own'])->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->post('/login', ['email' => $staff->email, 'password' => 'secret-pass'])->assertRedirect(route('desk.index'));
        $this->get('/')->assertRedirect(route('desk.index'));
        // Sent to log in from the Dashboard (or the home-screen app, which opens "/"): still Order management.
        $this->post('/logout');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/login', ['email' => $staff->email, 'password' => 'secret-pass'])->assertRedirect(route('desk.index'));
        // Sent away from another page: back to that page.
        $this->post('/logout');
        $this->get('/customers')->assertRedirect('/login');
        $this->post('/login', ['email' => $staff->email, 'password' => 'secret-pass'])->assertRedirect(url('/customers'));
        $this->seed(\Database\Seeders\OrderConfigSeeder::class);
        $this->get('/dashboard')->assertOk(); // still there when opened on purpose
        $this->post('/logout');

        $manager = User::factory()->create(['password' => 'manager-pass']);
        $manager->roles()->attach($this->role(['orders.view', 'orders.edit', 'orders.reassign'], [], 'Manager')->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->post('/login', ['email' => $manager->email, 'password' => 'manager-pass'])->assertRedirect(route('orders.activity'));
        $this->get('/')->assertRedirect(route('orders.activity'));
        $this->post('/logout');

        $owner = $this->owner(['password' => 'owner-pass']);
        $this->post('/login', ['email' => $owner->email, 'password' => 'owner-pass'])->assertRedirect(route('dashboard'));
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in');
    }

    public function test_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass', 'is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
