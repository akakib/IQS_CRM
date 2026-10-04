<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->owner(['name' => 'Owner']);
        $this->actingAs($this->owner);
    }

    public function test_owner_sees_roles_and_matrix(): void
    {
        $this->role(['staff.view'], [], 'Moderator');

        $this->get('/roles')->assertOk()->assertSee('Moderator');
        $this->get('/roles/create')->assertOk()->assertSee('Deactivate')->assertSee('Hide salary');
    }

    public function test_owner_creates_role_with_grants_scope_and_masks(): void
    {
        $this->post('/roles', [
            'name' => 'Packer',
            'grants' => ['locations.view', 'staff.view'],
            'scopes' => ['staff' => 'own'],
            'masks' => ['customer_contact'],
        ])->assertRedirect('/roles');

        $role = Role::firstWhere('name', 'Packer');
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        app(\App\Services\PermissionService::class)->bump();

        $this->assertTrue($user->can('locations.view'));
        $this->assertSame('own', $user->permissionScope('staff.view'));
        $this->assertFalse($user->can('staff.edit'));
        $this->assertFalse($user->canSeeField('customer_contact'));
    }

    public function test_editing_a_role_changes_access_immediately(): void
    {
        $role = $this->role(['staff.view'], [], 'Helper');
        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        $this->assertTrue($user->can('staff.view'));

        $this->put("/roles/{$role->id}", ['name' => 'Helper', 'grants' => ['locations.view']])->assertRedirect('/roles');

        $this->assertFalse($user->fresh()->can('staff.view'));
        $this->assertTrue($user->fresh()->can('locations.view'));
    }

    public function test_role_in_use_and_owner_role_cannot_be_deleted(): void
    {
        $role = $this->role([], [], 'Busy');
        User::factory()->create()->roles()->attach($role->id);
        $owner = Role::firstWhere('system_key', Role::OWNER);

        $this->delete("/roles/{$role->id}")->assertSessionHas('error');
        $this->delete("/roles/{$owner->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted($role);

        $free = $this->role([], [], 'Free');
        $this->delete("/roles/{$free->id}")->assertSessionHas('success');
        $this->assertSoftDeleted($free);
    }

    public function test_non_owner_cannot_manage_roles_or_access_even_with_roles_view(): void
    {
        $manager = User::factory()->create();
        $manager->roles()->attach($this->role(['roles.view', 'staff.view', 'staff.edit'])->id);
        app(\App\Services\PermissionService::class)->bump();
        $this->actingAs($manager);

        $this->get('/roles')->assertOk()->assertDontSee('New role');
        $this->get('/roles/create')->assertForbidden();
        $this->post('/roles', ['name' => 'Sneaky', 'grants' => ['staff.delete']])->assertForbidden();
        $this->get("/users/{$manager->id}/access")->assertForbidden();
        $this->post("/users/{$manager->id}/access/overrides", ['permission' => 'staff.delete', 'effect' => 'allow', 'reason' => 'x'])->assertForbidden();
    }

    public function test_owner_gives_temporary_role_with_reason(): void
    {
        $role = $this->role(['staff.view'], [], 'Manager');
        $staff = User::factory()->create();

        $this->post("/users/{$staff->id}/access/roles", ['role_id' => $role->id, 'expires_at' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('reason');

        $this->post("/users/{$staff->id}/access/roles", [
            'role_id' => $role->id, 'expires_at' => now()->addDay()->format('Y-m-d\TH:i'), 'reason' => 'Manager on leave',
        ])->assertSessionHas('success');

        $this->assertTrue($staff->fresh()->can('staff.view'));
        $this->assertDatabaseHas('user_roles', ['user_id' => $staff->id, 'reason' => 'Manager on leave', 'assigned_by' => $this->owner->id]);

        $this->get("/users/{$staff->id}/access")->assertOk()->assertSee('Temporary')->assertSee('Manager on leave');
    }

    public function test_owner_adds_and_removes_custom_allow_and_deny(): void
    {
        $staff = User::factory()->create();
        $staff->roles()->attach($this->role(['staff.view', 'staff.edit'])->id);

        $this->post("/users/{$staff->id}/access/overrides", ['permission' => 'staff.export', 'effect' => 'allow', 'reason' => 'Month end'])->assertSessionHas('success');
        $this->post("/users/{$staff->id}/access/overrides", ['permission' => 'staff.edit', 'effect' => 'deny', 'reason' => 'Under review'])->assertSessionHas('success');

        $this->assertTrue($staff->fresh()->can('staff.export'));
        $this->assertFalse($staff->fresh()->can('staff.edit'));

        $deny = DB::table('user_permissions')->where('effect', 'deny')->value('id');
        $this->delete("/users/{$staff->id}/access/overrides/{$deny}")->assertSessionHas('success');
        $this->assertTrue($staff->fresh()->can('staff.edit'));
    }

    public function test_last_owner_cannot_lose_the_owner_role(): void
    {
        $assignment = DB::table('user_roles')->where('user_id', $this->owner->id)->value('id');

        $this->delete("/users/{$this->owner->id}/access/roles/{$assignment}")->assertSessionHas('error');
        $this->assertTrue($this->owner->fresh()->isOwner());
    }
}
