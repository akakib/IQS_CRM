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
            'products.view', 'products.create', 'products.edit', 'products.availability', 'customers.view', 'customers.create', 'customers.edit',
            'orders.view', 'orders.create', 'orders.edit', 'orders.approve', 'orders.reassign', 'orders.export',
        ], ['salary']],
        'moderator' => ['Moderator', 'Sales and order confirmation.', ['products.view', 'customers.view', 'customers.create', 'customers.edit',
            'orders.view:own', 'orders.create', 'orders.edit', 'orders.take'], ['cost_price', 'profit', 'salary']],
        'dollar_keeper' => ['Dollar Keeper', 'USD purchases and ad spend.', [], ['customer_contact', 'salary']],
        'packaging' => ['Packaging', 'Picks and packs orders at the shop.', ['products.view', 'packing.view', 'packing.create'], ['customer_contact', 'cost_price', 'profit', 'salary']],
        'store_keeper' => ['Store Keeper', 'Receives and moves stock.', ['locations.view', 'products.view'], ['customer_contact', 'profit', 'salary']],
        'hr' => ['HR', 'Staff records, attendance and payroll.', ['staff.view', 'staff.create', 'staff.edit', 'staff.export'], ['customer_contact', 'cost_price', 'profit']],
    ];

    /**
     * Permissions added after the first install. If nobody at all holds one
     * yet, these system roles get it, so a new feature works without the
     * admin hunting for a tick box. Once any role has it, the admin decides.
     */
    private const LATER_GRANTS = [
        'orders.take' => ['moderator'],
        'packing.manage' => ['manager'],
        'attendance.view' => ['manager', 'hr'],
        'attendance.edit' => ['manager', 'hr'],
        'points.manage' => ['manager'],
        'kpi.view' => ['manager'],
        'analysis.view' => ['manager'],
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

            // "key:own" grants with the own-records scope; plain keys get all.
            DB::table('role_permissions')->insert(array_map(function ($g) use ($role, $permissionIds) {
                [$key, $scope] = array_pad(explode(':', $g), 2, 'all');

                return ['role_id' => $role->id, 'permission_id' => $permissionIds[$key], 'data_scope' => $scope];
            }, $grants));
            DB::table('role_field_masks')->insert(array_map(fn ($f) => ['role_id' => $role->id, 'field' => $f], $masks));
        }

        foreach (self::LATER_GRANTS as $key => $roleKeys) {
            $permissionId = $permissionIds[$key] ?? null;
            if (! $permissionId || DB::table('role_permissions')->where('permission_id', $permissionId)->exists()) {
                continue;
            }
            foreach (Role::whereIn('system_key', $roleKeys)->pluck('id') as $roleId) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'data_scope' => 'all']);
            }
        }

        app(PermissionService::class)->bump();
    }
}
