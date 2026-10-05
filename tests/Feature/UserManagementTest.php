<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->owner(['name' => 'Admin']);
        $this->actingAs($this->admin);
    }

    public function test_index_shows_table_and_cards_with_location(): void
    {
        $shop = Location::factory()->create(['name' => 'Riajuddin Bazar']);
        User::factory()->create(['name' => 'Mahim', 'work_location_id' => $shop->id, 'employment_type' => 'onsite']);

        $this->get('/users')->assertOk()
            ->assertSee('Mahim')->assertSee('Riajuddin Bazar')->assertSee('Onsite')
            ->assertSee('data-view="table"', false)->assertSee('data-view="cards"', false);
    }

    public function test_filters_work_from_the_query_string(): void
    {
        User::factory()->create(['name' => 'Mahim', 'phone' => '01711111111', 'employment_type' => 'remote']);
        User::factory()->create(['name' => 'Pranto', 'is_active' => false]);

        $names = fn (string $query) => $this->get('/users?'.$query)->viewData('users')->pluck('name')->all();

        $this->assertSame(['Mahim'], $names('q=0171'));
        $this->assertSame(['Mahim'], $names('employment_type=remote'));
        $this->assertSame(['Pranto'], $names('status=inactive'));
    }

    public function test_index_stays_within_query_budget(): void
    {
        $location = Location::factory()->create();
        User::factory()->count(40)->create(['work_location_id' => $location->id]);

        DB::enableQueryLog();
        $this->get('/users?per_page=25')->assertOk();

        // user + permissions (2) + count + select + location eager load + location options; list budget is 10
        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
    }

    public function test_staff_can_be_added_and_can_log_in(): void
    {
        $this->post('/users', [
            'name' => 'Mahim', 'email' => 'Mahim@Example.com', 'phone' => '01712345678',
            'employment_type' => 'onsite', 'password' => 'mahim-pass', 'password_confirmation' => 'mahim-pass',
        ])->assertRedirect('/users');

        $this->assertDatabaseHas('users', ['email' => 'mahim@example.com', 'is_active' => true, 'employment_type' => 'onsite']);

        auth()->logout();
        $this->post('/login', ['email' => 'mahim@example.com', 'password' => 'mahim-pass'])->assertRedirect('/dashboard');
    }

    public function test_create_validates_fields(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/users', ['name' => '', 'email' => 'taken@example.com', 'phone' => '999', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors(['name', 'email', 'phone', 'password']);
    }

    public function test_edit_keeps_password_when_left_empty_and_resets_when_filled(): void
    {
        $staff = User::factory()->create(['password' => 'original-pass']);

        $this->put("/users/{$staff->id}", ['name' => 'Renamed', 'email' => $staff->email])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('original-pass', $staff->fresh()->password));
        $this->assertSame('Renamed', $staff->fresh()->name);

        $this->put("/users/{$staff->id}", ['name' => 'Renamed', 'email' => $staff->email, 'password' => 'owner-set-pass', 'password_confirmation' => 'owner-set-pass'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('owner-set-pass', $staff->fresh()->password));
    }

    public function test_a_staff_photo_can_be_uploaded_replaced_and_removed(): void
    {
        $staff = User::factory()->create();
        $put = fn (array $extra) => $this->put("/users/{$staff->id}", ['name' => $staff->name, 'email' => $staff->email] + $extra);

        $put(['photo' => \Illuminate\Http\UploadedFile::fake()->image('me.jpg', 200, 200)])->assertSessionHasNoErrors();
        $first = $staff->fresh()->photo_path;
        $this->assertStringStartsWith('uploads/avatars/', $first);
        $this->assertFileExists(public_path($first));

        $put(['photo' => \Illuminate\Http\UploadedFile::fake()->image('new.png', 200, 200)])->assertSessionHasNoErrors();
        $second = $staff->fresh()->photo_path;
        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist(public_path($first)); // the old file is gone

        $put(['remove_photo' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($staff->fresh()->photo_path);
        $this->assertFileDoesNotExist(public_path($second));

        $put(['photo' => \Illuminate\Http\UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
    }

    public function test_staff_can_be_deactivated_and_then_cannot_log_in(): void
    {
        $staff = User::factory()->create(['password' => 'staff-pass']);

        $this->patch("/users/{$staff->id}/status")->assertSessionHas('success');
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);

        auth()->logout();
        $this->post('/login', ['email' => $staff->email, 'password' => 'staff-pass'])->assertSessionHasErrors('email');
    }

    public function test_you_cannot_deactivate_yourself(): void
    {
        $this->patch("/users/{$this->admin->id}/status")->assertSessionHas('error');
        $this->assertTrue($this->admin->fresh()->is_active);
    }
}
