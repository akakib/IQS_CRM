<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installed systems keep the admin's alert matrix, so the seeder never adds rules there. The types that were sent
 * with nobody set to receive them (a point dispute reached no manager; booking failed, scan stopped and the like
 * never went to Telegram) get the seeder's default rule here, only where the type still has no rule at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('notification_rules')->exists()) {
            return; // a fresh database: NotificationSeeder adds them all
        }
        $types = DB::table('notification_types')->pluck('id', 'system_key');
        $roles = DB::table('roles')->whereNotNull('system_key')->pluck('id', 'system_key');
        foreach (['points_review', 'booking_failed', 'courier_cancel_needed', 'cod_update_needed', 'scan_stopped', 'order_held_by_packer', 'return_problem', 'orders_unassigned', 'hold_limit_request'] as $type) {
            if (! isset($types[$type]) || DB::table('notification_rules')->where('type_id', $types[$type])->exists()) {
                continue;
            }
            foreach (\Database\Seeders\NotificationSeeder::defaultsFor($type) as [$target, $roleKey, $inApp, $telegram]) {
                if ($roleKey && ! isset($roles[$roleKey])) {
                    continue;
                }
                DB::table('notification_rules')->insert([
                    'type_id' => $types[$type], 'target' => $target, 'role_id' => $roleKey ? $roles[$roleKey] : null,
                    'channel_in_app' => $inApp, 'channel_telegram' => $telegram, 'channel_sms' => false, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void {}
};
