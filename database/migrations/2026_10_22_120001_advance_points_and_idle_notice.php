<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * An advance taken: +2 when delivered, and a cancel after it costs half a point instead of one.
 * An order left untouched until it is taken back: the managers are told who and which.
 * On an installed system only (a fresh one gets these from the seeders); an edited rule is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('point_rules')->exists()) {
            $rule = fn (string $trigger, string $name, float $points, array $conditions) => DB::table('point_rules')->where('trigger_key', $trigger)->where('name', $name)->exists() ? null
                : tap(DB::table('point_rules')->insertGetId([
                    'trigger_key' => $trigger, 'name' => $name, 'points' => $points, 'recipient' => 'order_moderator', 'settle_on' => 'order_final',
                    'requires_delivery' => false, 'is_active' => true, 'sort_order' => 60, 'created_at' => now(), 'updated_at' => now(),
                ]), fn ($id) => DB::table('point_rule_conditions')->insert(array_map(fn ($c) => ['rule_id' => $id, 'field' => $c[0], 'operator' => '=', 'value' => $c[1]], $conditions)));

            $rule('order_delivered', 'Delivered with an advance taken', 2, [['advance_taken', 'true']]);
            // The old "any reason" cancel becomes "no advance taken" (only if nobody changed its conditions).
            $old = DB::table('point_rules')->where('trigger_key', 'order_cancelled')->where('name', 'Order cancelled (any reason)')->first();
            if ($old && ! DB::table('point_rule_conditions')->where('rule_id', $old->id)->exists()) {
                DB::table('point_rules')->where('id', $old->id)->update(['name' => 'Order cancelled (no advance taken)', 'updated_at' => now()]);
                DB::table('point_rule_conditions')->insert(['rule_id' => $old->id, 'field' => 'advance_taken', 'operator' => '=', 'value' => 'false']);
            }
            $rule('order_cancelled', 'Order cancelled after an advance was taken', -0.5, [['advance_taken', 'true']]);
        }

        Artisan::call('notifications:sync');
        $type = DB::table('notification_types')->where('system_key', 'order_left_idle')->value('id');
        $manager = DB::table('roles')->where('system_key', 'manager')->value('id');
        if (DB::table('notification_rules')->exists() && $type && $manager && ! DB::table('notification_rules')->where('type_id', $type)->exists()) {
            DB::table('notification_rules')->insert(['type_id' => $type, 'target' => 'role', 'role_id' => $manager, 'channel_in_app' => true, 'channel_telegram' => true,
                'channel_sms' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
