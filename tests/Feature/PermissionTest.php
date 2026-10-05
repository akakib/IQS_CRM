<?php

namespace Tests\Feature;

use App\Models\Concerns\ScopedByPermission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\Permissions\Mask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    private function giveRole(User $user, Role $role, array $pivot = []): void
    {
        $user->roles()->attach($role->id, $pivot);
        app(PermissionService::class)->bump();
    }

    private function override(User $user, string $key, string $effect, array $extra = []): void
    {
        Artisan::call('permissions:sync');
        DB::table('user_permissions')->insert([
            'user_id' => $user->id,
            'permission_id' => DB::table('permissions')->where('key', $key)->value('id'),
            'effect' => $effect,
        ] + $extra);
        app(PermissionService::class)->bump();
    }

    public function test_user_without_permission_gets_403(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/users')->assertForbidden();
        $this->get('/locations')->assertForbidden();
        $this->get('/dashboard')->assertOk();
    }

    public function test_role_grants_only_what_is_ticked(): void
    {
        $user = User::factory()->create();
        $this->giveRole($user, $this->role(['locations.view']));
        $this->actingAs($user);

        $this->get('/locations')->assertOk()->assertDontSee('New location');
        $this->get('/locations/create')->assertForbidden();
        $this->get('/users')->assertForbidden();
    }

    public function test_owner_has_everything_including_new_modules(): void
    {
        $owner = $this->owner();

        config(['permissions.modules.orders' => ['view', 'export']]);
        Artisan::call('permissions:sync');

        $this->assertTrue($owner->hasPermission('orders.export'));
        $this->assertTrue($owner->canSeeField('salary'));
    }

    public function test_new_module_starts_not_allowed_for_existing_roles(): void
    {
        $user = User::factory()->create();
        $this->giveRole($user, $this->role(['staff.view', 'staff.edit']));

        config(['permissions.modules.orders' => ['view']]);
        Artisan::call('permissions:sync');

        $this->assertTrue($user->hasPermission('staff.view'));
        $this->assertFalse($user->hasPermission('orders.view'));
        $this->assertDatabaseHas('permissions', ['key' => 'orders.view', 'is_active' => true]);
    }

    public function test_user_override_allow_adds_and_deny_wins_over_role(): void
    {
        $user = User::factory()->create();
        $this->giveRole($user, $this->role(['staff.view', 'staff.edit']));

        $this->override($user, 'locations.view', 'allow');
        $this->override($user, 'staff.edit', 'deny');

        $this->assertTrue($user->can('locations.view'));
        $this->assertTrue($user->can('staff.view'));
        $this->assertFalse($user->can('staff.edit'));
    }

    public function test_widest_scope_wins_across_roles(): void
    {
        $user = User::factory()->create();
        $this->giveRole($user, $this->role(['staff.view' => 'own'], [], 'A'));
        $this->giveRole($user, $this->role(['staff.view' => 'all'], [], 'B'));

        $this->assertSame('all', $user->permissionScope('staff.view'));
    }

    public function test_data_scope_own_hides_other_users_records(): void
    {
        Schema::create('test_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('moderator_id');
            $t->string('body');
        });
        $note = new class extends Model
        {
            use ScopedByPermission;

            protected $table = 'test_notes';

            public $timestamps = false;

            protected $guarded = [];
        };

        config(['permissions.modules.notes' => ['view']]);

        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->giveRole($alice, $this->role(['notes.view' => 'own']));
        $note->newQuery()->insert([['moderator_id' => $alice->id, 'body' => 'mine'], ['moderator_id' => $bob->id, 'body' => 'theirs']]);

        $this->assertSame(['mine'], $note->newQuery()->visibleTo($alice, 'notes.view')->pluck('body')->all());
        $this->assertSame([], $note->newQuery()->visibleTo($bob, 'notes.view')->pluck('body')->all());
        $this->assertCount(2, $note->newQuery()->visibleTo($this->owner(), 'notes.view')->get());
    }

    public function test_field_mask_hides_customer_contact(): void
    {
        $user = User::factory()->create();
        $this->giveRole($user, $this->role(['staff.view'], ['customer_contact']));

        $this->assertFalse($user->canSeeField('customer_contact'));
        $this->assertTrue($user->canSeeField('cost_price'));
        $this->assertSame('017******78', Mask::value('01712345678', 'customer_contact', $user));
        $this->assertSame('500', Mask::value('500', 'cost_price', $user));
    }

    public function test_field_is_visible_if_any_role_shows_it(): void
    {
        $user = User::factory()->create();
        $this->giveRole($user, $this->role([], ['salary', 'profit'], 'A'));
        $this->giveRole($user, $this->role([], ['salary'], 'B'));

        $this->assertFalse($user->canSeeField('salary'));
        $this->assertTrue($user->canSeeField('profit'));
    }

    public function test_user_without_any_role_sees_no_sensitive_fields(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->canSeeField('customer_contact'));
        $this->assertFalse($user->canSeeField('salary'));
    }

    public function test_expired_temporary_role_gives_no_access_and_is_pruned(): void
    {
        $user = User::factory()->create();
        $role = $this->role(['staff.view']);
        $this->giveRole($user, $role, ['expires_at' => now()->subMinute()]);

        $this->assertFalse($user->can('staff.view'));

        Artisan::call('permissions:prune-expired');
        $this->assertDatabaseMissing('user_roles', ['user_id' => $user->id, 'role_id' => $role->id]);
    }

    public function test_temporary_access_ends_exactly_at_expiry_even_when_cached(): void
    {
        $user = User::factory()->create();
        $this->override($user, 'staff.export', 'allow', ['expires_at' => now()->addMinutes(10)]);

        $this->assertTrue($user->can('staff.export'));

        $this->travel(11)->minutes();
        app()->forgetInstance(PermissionService::class); // a new request

        $this->assertFalse($user->fresh()->can('staff.export'));
    }

    public function test_future_role_is_not_active_yet(): void
    {
        $user = User::factory()->create();
        $this->giveRole($user, $this->role(['staff.view']), ['starts_at' => now()->addDay()]);

        $this->assertFalse($user->can('staff.view'));
    }

    public function test_many_checks_cost_no_extra_queries(): void
    {
        $user = User::factory()->create();
        $this->giveRole($user, $this->role(['staff.view']));
        $user->can('staff.view'); // warm

        DB::enableQueryLog();
        for ($i = 0; $i < 50; $i++) {
            $user->can('staff.view');
            $user->can('locations.edit');
            $user->canSeeField('salary');
        }

        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_sidebar_shows_only_permitted_modules(): void
    {
        $user = User::factory()->create();
        $this->giveRole($user, $this->role(['locations.view']));

        $this->actingAs($user)->get('/dashboard')->assertSee('Locations')->assertDontSee('>Staff<', false)->assertDontSee('>Team<', false);
    }
}
