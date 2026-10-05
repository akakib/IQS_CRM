<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default statuses, transitions, reasons, delivery charges and payment
 * methods. Only inserts what is missing, so admin edits survive re-seeding.
 */
class OrderConfigSeeder extends Seeder
{
    /** key => [name, colour, stage group, final, requires reason, edit policy, counts as sale] */
    private const STATUSES = [
        'new' => ['New', '#2563eb', 'intake', false, false, 'free', false],
        'record_verified' => ['Record verified', '#0891b2', 'verification', false, false, 'free', false],
        'no_answer' => ['No answer', '#f59e0b', 'side', false, false, 'free', false],
        'hold' => ['Hold', '#d97706', 'side', false, true, 'free', false],
        'confirmed' => ['Confirmed', '#16a34a', 'verification', false, false, 'free', true],
        'ready_for_packaging' => ['Ready for packaging', '#7c3aed', 'fulfillment', false, false, 'approval', true],
        'packed' => ['Packed', '#9333ea', 'fulfillment', false, false, 'approval', true],
        'ready_for_pickup' => ['Ready for pickup', '#a855f7', 'fulfillment', false, false, 'approval', true],
        'handed_over' => ['Handed over', '#0f766e', 'courier', false, false, 'locked', true],
        'in_transit' => ['In transit', '#0d9488', 'courier', false, false, 'locked', true],
        'delivered' => ['Delivered', '#15803d', 'final', true, false, 'locked', true],
        'partial_delivered' => ['Partial delivered', '#65a30d', 'final', true, false, 'locked', true],
        'returned' => ['Returned', '#dc2626', 'final', true, true, 'locked', false],
        'cancelled' => ['Cancelled', '#6b7280', 'final', true, true, 'locked', false],
    ];

    /** [from, to, permission, requires reason, system only] */
    private const TRANSITIONS = [
        ['new', 'record_verified', 'orders.edit', false, false],             // manual review by the moderator; rules do the rest
        ['new', 'hold', 'orders.edit', true, false],
        ['new', 'cancelled', 'orders.edit', true, false],
        ['record_verified', 'confirmed', 'orders.edit', false, false],
        ['record_verified', 'no_answer', 'orders.edit', false, false],
        ['record_verified', 'hold', 'orders.edit', true, false],
        ['record_verified', 'cancelled', 'orders.edit', true, false],
        ['no_answer', 'confirmed', 'orders.edit', false, false],
        ['no_answer', 'no_answer', 'orders.edit', false, false],             // another failed call attempt
        ['no_answer', 'hold', 'orders.edit', true, false],
        ['no_answer', 'cancelled', 'orders.edit', true, false],
        ['hold', 'record_verified', 'orders.edit', false, false],
        ['hold', 'confirmed', 'orders.edit', false, false],
        ['hold', 'cancelled', 'orders.edit', true, false],
        ['hold', 'ready_for_packaging', 'orders.edit', false, false],        // packer hold solved: back to the packaging queue (CN gate still applies)
        ['confirmed', 'hold', 'orders.edit', true, false],
        ['confirmed', 'cancelled', 'orders.edit', true, false],
        ['confirmed', 'record_verified', null, false, true],                 // Undo within seconds of Call verified (before the courier is booked)
        ['confirmed', 'ready_for_packaging', null, false, true],             // bulk booking (needs CN ID)
        ['ready_for_packaging', 'packed', null, false, true],
        ['ready_for_packaging', 'record_verified', null, false, true],      // booked by mistake (DeskService::takeBack)
        ['packed', 'record_verified', null, false, true],
        ['ready_for_pickup', 'record_verified', null, false, true],                // label scan
        ['ready_for_packaging', 'cancelled', 'orders.approve', true, false],
        ['ready_for_packaging', 'hold', 'orders.approve', true, false],        // item went out of stock after booking
        ['packed', 'ready_for_pickup', null, false, true],
        ['packed', 'cancelled', 'orders.approve', true, false],
        ['ready_for_pickup', 'handed_over', null, false, true],             // handover scan
        ['ready_for_pickup', 'cancelled', 'orders.approve', true, false],
        ['handed_over', 'in_transit', null, false, true],                    // courier webhook
        ['handed_over', 'delivered', null, false, true],
        ['handed_over', 'partial_delivered', null, false, true],
        ['handed_over', 'returned', null, true, true],
        ['in_transit', 'delivered', null, false, true],
        ['in_transit', 'partial_delivered', null, false, true],
        ['in_transit', 'returned', null, true, true],
        // Courier picked up although the handover scan was skipped: never leave an order stuck.
        ['ready_for_packaging', 'in_transit', null, false, true],
        ['packed', 'in_transit', null, false, true],
        ['ready_for_pickup', 'in_transit', null, false, true],
        ['cancelled', 'new', 'orders.approve', true, false],                 // reopen by mistake
    ];

    /** [type, label, blame stage, system key, release mode, counts as break (break reasons only)] */
    private const REASONS = [
        ['hold', 'Stock arriving (pre-order)', 'none', 'awaiting_stock', 'on_restock'],
        ['hold', 'Stock out', 'none', 'stock_out', 'manual'],
        ['hold', 'Deliver on a date', 'customer', 'scheduled', 'on_date'],
        ['hold', 'Customer asked to wait', 'customer', 'customer_wait', 'manual'],
        ['hold', 'Waiting for advance payment', 'none', 'advance_wait', 'manual'],
        ['hold', 'Item not found while packaging', 'none', 'item_not_found', 'manual'],
        ['hold', 'Damaged or wrong item on the shelf', 'none', 'item_damaged', 'manual'],
        ['cancel', 'Customer cancelled', 'customer', 'customer_cancelled', 'manual'],
        ['cancel', 'Fake or prank order', 'customer', 'fake_order', 'manual'],
        ['cancel', 'Duplicate order', 'none', 'duplicate', 'manual'],
        ['cancel', 'Out of stock', 'none', 'out_of_stock', 'manual'],
        ['cancel', 'Price or offer issue', 'sales', 'price_issue', 'manual'],
        ['cancel', 'Could not reach customer', 'customer', 'unreachable', 'manual'],
        ['cancel', 'Entry mistake', 'sales', 'entry_error', 'manual'],
        ['cancel', 'No advance payment', 'customer', 'no_advance', 'manual'],
        ['cancel', 'Cancelled on the website', 'customer', 'website_cancelled', 'manual'],
        ['amendment', 'Customer request', 'customer', 'customer_request', 'manual'],
        ['amendment', 'Stock out, substitute given', 'none', 'stock_out_substitute', 'manual'],
        ['amendment', 'Entry error', 'sales', 'entry_error', 'manual'],
        ['amendment', 'Advance payment changed the COD', 'none', 'payment_cod', 'manual'],
        ['amendment', 'Price error', 'sales', 'price_error', 'manual'],
        ['amendment', 'Advance payment verified', 'none', 'advance_verified', 'manual'],
        ['return', 'Customer refused at door', 'customer', 'refused', 'manual'],
        ['return', 'Wrong or missing item', 'packaging', 'wrong_item', 'manual'],
        ['return', 'Wrong address or phone', 'sales', 'wrong_address', 'manual'],
        ['return', 'Courier failed', 'courier', 'courier_failed', 'manual'],
        ['return', 'Returned, reason not set yet', 'none', 'unclassified', 'manual'],
        ['status', 'Reopened by mistake', 'none', 'reopen', 'manual'],
        ['reassign', 'Moderator on leave or off shift', 'none', 'on_leave', 'manual'],
        ['reassign', 'Moderator could not handle it', 'sales', 'moderator_error', 'manual'],
        ['reassign', 'Workload balance', 'none', 'workload', 'manual'],
        // Breaks: the last value says whether it counts towards the daily break limit.
        ['break', 'Lunch', 'none', 'lunch', 'manual', true],
        ['break', 'Prayer', 'none', 'prayer', 'manual', true],
        ['break', 'Washroom', 'none', 'washroom', 'manual', true],
        ['break', 'Shop visit', 'none', 'shop_visit', 'manual', false],
        ['break', 'Task from admin', 'none', 'admin_task', 'manual', false],
        ['break', 'Other', 'none', 'other', 'manual', true],
    ];

    public function run(): void
    {
        $now = now();
        $order = 0;
        foreach (self::STATUSES as $key => [$name, $color, $group, $final, $reason, $policy, $sale]) {
            $order++;
            if (! DB::table('order_statuses')->where('system_key', $key)->exists()) {
                DB::table('order_statuses')->insert([
                    'system_key' => $key, 'name_en' => $name, 'color' => $color, 'stage_group' => $group,
                    'is_system' => true, 'is_final' => $final, 'requires_reason' => $reason, 'edit_policy' => $policy,
                    'counts_as_sale' => $sale, 'sort_order' => $order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        $ids = DB::table('order_statuses')->whereNotNull('system_key')->pluck('id', 'system_key');

        foreach (self::TRANSITIONS as [$from, $to, $permission, $reason, $system]) {
            if (! DB::table('order_status_transitions')->where('from_status_id', $ids[$from])->where('to_status_id', $ids[$to])->exists()) {
                DB::table('order_status_transitions')->insert([
                    'from_status_id' => $ids[$from], 'to_status_id' => $ids[$to], 'permission_key' => $permission,
                    'requires_reason' => $reason, 'system_only' => $system, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $order = 0;
        foreach (self::REASONS as $reason) {
            [$type, $label, $blame, $key, $release] = $reason;
            $order++;
            if (! DB::table('status_reasons')->where('reason_type', $type)->where('system_key', $key)->exists()) {
                DB::table('status_reasons')->insert([
                    'reason_type' => $type, 'label_en' => $label, 'blame_stage' => $blame, 'system_key' => $key,
                    'release_mode' => $release, 'counts_as_break' => $reason[5] ?? true, 'is_active' => true, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if (! DB::table('delivery_charge_rules')->exists()) {
            $zones = DB::table('delivery_zones')->pluck('id', 'system_key');
            foreach ([
                ['inside_dhaka', 0, 1000, 70], ['inside_dhaka', 1001, 2000, 90], ['inside_dhaka', 2001, null, 110],
                ['sub_dhaka', 0, 1000, 100], ['sub_dhaka', 1001, null, 130],
                ['outside_dhaka', 0, 1000, 130], ['outside_dhaka', 1001, 2000, 150], ['outside_dhaka', 2001, null, 180],
            ] as [$zone, $min, $max, $charge]) {
                if (isset($zones[$zone])) {
                    DB::table('delivery_charge_rules')->insert([
                        'zone_id' => $zones[$zone], 'min_weight_g' => $min, 'max_weight_g' => $max, 'min_order_total' => 0,
                        'charge' => $charge, 'priority' => 100, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }

        foreach ([['bkash', 'bKash', true], ['nagad', 'Nagad', true], ['rocket', 'Rocket', true], ['bank', 'Bank transfer', true], ['card', 'Card', true], ['cash', 'Cash', false]] as [$key, $name, $trx]) {
            if (! DB::table('payment_methods')->where('system_key', $key)->exists()) {
                DB::table('payment_methods')->insert(['system_key' => $key, 'name' => $name, 'requires_trx_id' => $trx, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
