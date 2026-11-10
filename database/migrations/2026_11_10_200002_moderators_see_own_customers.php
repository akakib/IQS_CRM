<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Owner's decision 2026-11-10: a moderator lists only their own customers (a full number still finds anyone). */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('system_key', 'moderator')->value('id');
        $perm = DB::table('permissions')->where('key', 'customers.view')->value('id');
        if ($role && $perm) {
            DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $perm)->update(['data_scope' => 'own']);
            app(\App\Services\PermissionService::class)->bump();
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('system_key', 'moderator')->value('id');
        $perm = DB::table('permissions')->where('key', 'customers.view')->value('id');
        if ($role && $perm) {
            DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $perm)->update(['data_scope' => 'all']);
            app(\App\Services\PermissionService::class)->bump();
        }
    }
};
