<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Packers are told (in the app and on Telegram) when an order already in their list is put on hold. */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('notifications:sync');
        $type = DB::table('notification_types')->where('system_key', 'order_held_in_shop')->value('id');
        $role = DB::table('roles')->where('system_key', 'packaging')->value('id');
        if (DB::table('notification_rules')->exists() && $type && $role && ! DB::table('notification_rules')->where('type_id', $type)->exists()) {
            DB::table('notification_rules')->insert(['type_id' => $type, 'target' => 'role', 'role_id' => $role, 'channel_in_app' => true, 'channel_telegram' => true,
                'channel_sms' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
