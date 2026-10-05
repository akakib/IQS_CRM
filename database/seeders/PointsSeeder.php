<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starting point rules, as agreed with the business owner. Every value is editable in
 * Settings > Points; this only adds rules that do not exist yet (by name).
 */
class PointsSeeder extends Seeder
{
    public function run(): void
    {
        // [trigger, name, points, recipient, settle_on, requires_delivery, conditions [field, op, value]]
        $rules = [
            ['order_delivered', 'Order delivered', 3, 'order_moderator', 'order_final', false, []],
            ['order_delivered', 'Saved order bonus (had No response or Hold, then delivered)', 2, 'order_moderator', 'order_final', false, [['saved', '=', 'true']]],
            ['order_delivered', 'Repeat customer order the moderator entered (follow-up sale)', 2, 'order_moderator', 'order_final', false, [['repeat_customer', '=', 'true'], ['own_entry', '=', 'true']]],
            ['order_partial', 'Partial delivery', 1, 'order_moderator', 'order_final', false, []],
            ['order_cancelled', 'Order cancelled (any reason)', -1, 'order_moderator', 'order_final', false, []],
            ['order_returned', 'Returned by a sales mistake', -3, 'order_moderator', 'order_final', false, [['blame', '=', 'sales']]],
            ['order_returned', 'Returned by a packing mistake', -3, 'packer', 'order_final', false, [['blame', '=', 'packing']]],
            ['order_packed', 'Packed within 30 minutes of release', 1, 'packer', 'order_final', false, [['minutes_since_release', '<=', '30']]],
            ['amendment', 'Entry error fixed before packing', -1, 'order_moderator', 'immediate', false, [['blame', '=', 'sales'], ['after_pack', '=', 'false']]],
            ['amendment', 'Entry error fixed after packing', -2, 'order_moderator', 'immediate', false, [['blame', '=', 'sales'], ['after_pack', '=', 'true']]],
            ['order_reassigned', 'Order taken away for own mistake', -1, 'previous_moderator', 'immediate', false, [['blame', '=', 'sales']]],
            ['issue_escalated', 'Delivery issue not handled in time', -1, 'order_moderator', 'immediate', false, []],
            ['timer_extended', 'Took extra time on the timer', -0.5, 'actor', 'immediate', false, []],
            ['timer_missed', 'Action timer missed', -1, 'actor', 'immediate', false, []],
            ['timer_missed', 'Action timer missed again (more than 3 today)', -1, 'actor', 'immediate', false, [['releases_today', '>', '3']]],
            ['fake_status', 'Fake status change (confirmed by a manager)', -10, 'actor', 'immediate', false, []],
        ];

        foreach ($rules as $i => [$trigger, $name, $points, $recipient, $settle, $delivery, $conditions]) {
            if (DB::table('point_rules')->where('trigger_key', $trigger)->where('name', $name)->exists()) {
                continue;
            }
            $id = DB::table('point_rules')->insertGetId([
                'trigger_key' => $trigger, 'name' => $name, 'points' => $points, 'recipient' => $recipient,
                'settle_on' => $settle, 'requires_delivery' => $delivery, 'is_active' => true, 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($conditions as [$field, $op, $value]) {
                DB::table('point_rule_conditions')->insert(['rule_id' => $id, 'field' => $field, 'operator' => $op, 'value' => $value]);
            }
        }
    }
}
