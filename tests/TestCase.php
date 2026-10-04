<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /** A user holding the Owner role (full access). */
    protected function owner(array $attributes = []): User
    {
        Artisan::call('permissions:sync');
        $role = Role::firstOrCreate(['system_key' => Role::OWNER], ['name' => 'Owner']);
        $user = User::factory()->create($attributes);
        $user->roles()->attach($role->id);
        app(PermissionService::class)->bump();

        return $user;
    }

    /** A role granting the given permission keys (key => scope, or plain keys = all) and masks. */
    protected function role(array $grants = [], array $masks = [], string $name = 'Test role'): Role
    {
        Artisan::call('permissions:sync');
        $role = Role::create(['name' => $name]);
        $ids = DB::table('permissions')->pluck('id', 'key');

        foreach ($grants as $key => $scope) {
            if (is_int($key)) {
                [$key, $scope] = [$scope, 'all'];
            }
            DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $ids[$key], 'data_scope' => $scope]);
        }
        foreach ($masks as $field) {
            DB::table('role_field_masks')->insert(['role_id' => $role->id, 'field' => $field]);
        }
        app(PermissionService::class)->bump();

        return $role;
    }
}
