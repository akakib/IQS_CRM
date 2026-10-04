<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Default roles. Safe to re-run: a role that already exists is left exactly
 * as the admin edited it; only missing roles are created with these defaults.
 */
class RoleSeeder extends Seeder
{
    private const ROLES = [
        'owner' => ['Owner', 'Full access to everything, including new modules.', [], []],
        'manager' => ['Manager', 'Operations lead.', [
            'staff.view', 'staff.create', 'staff.edit', 'locations.view', 'locations.create', 'locations.edit', 'roles.view',
        ], ['salary']],
        'moderator' => ['Moderator', 'Sales and order confirmation.', [], ['cost_price', 'profit', 'salary']],
        'dollar_keeper' => ['Dollar Keeper', 'USD purchases and ad spend.', [], ['customer_contact', 'salary']],
        'packaging' => ['Packaging', 'Picks and packs orders at the shop.', [], ['customer_contact', 'cost_price', 'profit', 'salary']],
        'store_keeper' => ['Store Keeper', 'Receives and moves stock.', ['locations.view'], ['customer_contact', 'profit', 'salary']],
        'hr' => ['HR', 'Staff records, attendance and payroll.', ['staff.view', 'staff.create', 'staff.edit', 'staff.export'], ['customer_contact', 'cost_price', 'profit']],
    ];

    public function run(): void
    {
        Artisan::call('permissions:sync');
        $permissionIds = Permission::pluck('id', 'key');

        foreach (self::ROLES as $key => [$name, $description, $grants, $masks]) {
            $role = Role::withTrashed()->firstWhere('system_key', $key);
            if ($role) {
                continue;
            }

            $role = Role::create(['name' => $name, 'system_key' => $key, 'description' => $description]);

            DB::table('role_permissions')->insert(array_map(fn ($g) => [
                'role_id' => $role->id, 'permission_id' => $permissionIds[$g], 'data_scope' => 'all',
            ], $grants));
            DB::table('role_field_masks')->insert(array_map(fn ($f) => ['role_id' => $role->id, 'field' => $f], $masks));
        }

        app(PermissionService::class)->bump();
    }
}
