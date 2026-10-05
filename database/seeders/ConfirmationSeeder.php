<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Example verification rules (DB_DESIGN 1.3) and the Purchase event timing. Only on a fresh install. */
class ConfirmationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        if (! DB::table('verification_rules')->exists()) {
            $steadfast = DB::table('fraud_check_providers')->where('system_key', 'steadfast')->value('id');
            $rules = [
                ['Blocked customer', 10, 'manual_review', [['customer_is_blocked', null, '=', 'true']]],
                ['Fully prepaid', 20, 'record_verified', [['advance_paid_percent', null, '>=', '100']]],              // still called: the call can add items
                ['Trusted repeat customer', 30, 'record_verified', [['own_delivered_count', null, '>=', '2'], ['own_return_count', null, '=', '0']]],
                ['Good Steadfast history', 40, 'record_verified', [['provider_success_rate', $steadfast, '>=', '75'], ['provider_total_parcels', $steadfast, '>=', '3']]],
                ['Weak Steadfast history', 45, 'hold_for_advance', [['provider_success_rate', $steadfast, '<', '75'], ['provider_total_parcels', $steadfast, '>=', '3']]],
                ['New to Steadfast (fewer than 3 parcels)', 50, 'hold_for_advance', [['provider_total_parcels', $steadfast, '<', '3']]],
                ['Everything else', 999, 'manual_review', []],
            ];
            foreach ($rules as [$name, $priority, $outcome, $conditions]) {
                $id = DB::table('verification_rules')->insertGetId([
                    'name' => $name, 'priority' => $priority, 'outcome' => $outcome, 'applies_to_channel' => 'all',
                    'stop_on_match' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($conditions as [$field, $provider, $op, $value]) {
                    DB::table('verification_rule_conditions')->insert(['rule_id' => $id, 'field' => $field, 'provider_id' => $provider, 'operator' => $op, 'value' => $value]);
                }
            }
        }

        if (! DB::table('tracking_event_settings')->exists()) {
            DB::table('tracking_event_settings')->insert([
                'platform' => 'meta_capi', 'event_name' => 'Purchase', 'fire_on' => 'confirmed', 'value_basis' => 'order_total',
                'channels' => json_encode(['web', 'messenger', 'whatsapp', 'phone']), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
