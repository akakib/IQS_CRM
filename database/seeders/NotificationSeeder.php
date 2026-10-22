<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Event types always; the default alert matrix only on a fresh install. */
class NotificationSeeder extends Seeder
{
    /** type => [[target, role system_key|null, in_app, telegram], ...] */
    private const DEFAULT_RULES = [
        'new_order' => [['role', 'moderator', true, false]],
        'order_needs_repack' => [['role', 'packaging', true, true], ['role', 'manager', true, false]],
        'delivery_issue' => [['order_moderator', null, true, true]],
        'stock_issue_reported' => [['role', 'owner', true, true], ['role', 'manager', true, false]],
        'amendment_pending_approval' => [['role', 'manager', true, false]],
        'sync_failed' => [['role', 'manager', true, false]],
        'vendor_payment_due' => [['role', 'dollar_keeper', true, false], ['role', 'owner', true, false]],
        'points_review' => [['role', 'manager', true, false]],
        'booking_failed' => [['role', 'manager', true, true]],
        'courier_cancel_needed' => [['role', 'manager', true, true]],
        'cod_update_needed' => [['role', 'manager', true, true]],
        'scan_stopped' => [['role', 'manager', true, true]],
        'order_held_by_packer' => [['role', 'manager', true, true]],
        'return_problem' => [['role', 'manager', true, true]],
        'orders_unassigned' => [['role', 'manager', true, true]],
        'hold_limit_request' => [['role', 'manager', true, false]],
        'order_left_idle' => [['role', 'manager', true, true]],
    ];

    /** The rules a type gets when it has none yet (an installed system keeps the admin's matrix for the rest). */
    public static function defaultsFor(string $type): array
    {
        return self::DEFAULT_RULES[$type] ?? [];
    }

    public function run(): void
    {
        Artisan::call('notifications:sync');

        if (DB::table('notification_rules')->exists()) {
            return; // the admin owns the matrix after the first install
        }

        $types = DB::table('notification_types')->pluck('id', 'system_key');
        $roles = DB::table('roles')->whereNotNull('system_key')->pluck('id', 'system_key');
        $now = now();

        foreach (self::DEFAULT_RULES as $type => $rules) {
            foreach ($rules as [$target, $roleKey, $inApp, $telegram]) {
                if ($roleKey && ! isset($roles[$roleKey])) {
                    continue;
                }
                DB::table('notification_rules')->insert([
                    'type_id' => $types[$type],
                    'target' => $target,
                    'role_id' => $roleKey ? $roles[$roleKey] : null,
                    'channel_in_app' => $inApp,
                    'channel_telegram' => $telegram,
                    'channel_sms' => false,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
