<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Managers give staff their roles (not Owner or Manager, never their own); making or changing roles stays with the Owner. */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('permissions:sync');
        $permission = DB::table('permissions')->where('key', 'staff.roles')->value('id');
        $manager = DB::table('roles')->where('system_key', 'manager')->whereNull('deleted_at')->value('id');
        if ($permission && $manager && ! DB::table('role_permissions')->where('role_id', $manager)->where('permission_id', $permission)->exists()) {
            DB::table('role_permissions')->insert(['role_id' => $manager, 'permission_id' => $permission, 'data_scope' => 'all']);
            app(\App\Services\PermissionService::class)->bump();
        }
    }

    public function down(): void {}
};
