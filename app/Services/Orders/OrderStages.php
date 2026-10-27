<?php

namespace App\Services\Orders;

use App\Models\OrderStatus;

/**
 * The stages an order passes through, as the admin sees them: the Order
 * activity columns plus the finished ones. One place for the rule behind
 * each stage, so the board and the export always agree.
 */
class OrderStages
{
    /** Stages still moving: the Order activity columns, left to right. Each is a condition on the order row ("o"). */
    public static function moving(): array
    {
        $s = fn (string $key) => OrderStatus::idFor($key);
        $now = now()->toDateTimeString();
        $advance = (int) app(DeskService::class)->advanceReasonId(); // an advance hold is a call to make, as on the Desk

        return [
            // Cancelled after booking, still to delete at the courier (by hand): first, it costs money if a rider takes it.
            'courier_cancel' => ['label' => __('Delete at courier'), 'sql' => 'o.status_id = ? AND EXISTS (SELECT 1 FROM shipments sc WHERE sc.id = o.active_shipment_id AND sc.cancelled_at IS NULL AND sc.final_at IS NULL)', 'bind' => [$s('cancelled')], 'tone' => 'red'],
            // Anything waiting with nobody on it (also an order taken back after a missed timer) is "nobody took", like the Control room counts it.
            'waiting' => ['label' => __('New, nobody took'), 'sql' => 'o.moderator_id IS NULL AND (o.status_id IN (?, ?, ?) OR (o.status_id = ? AND o.hold_reason_id = ? AND o.advance_waived_at IS NULL AND o.advance_required IS NOT NULL))', 'bind' => [$s('new'), $s('record_verified'), $s('no_answer'), $s('hold'), $advance], 'tone' => 'gray'],
            'verify' => ['label' => __('Verify'), 'sql' => 'o.status_id = ? AND o.moderator_id IS NOT NULL', 'bind' => [$s('new')], 'tone' => 'blue'],
            'call' => ['label' => __('Call'), 'sql' => 'o.moderator_id IS NOT NULL AND (o.status_id = ? OR (o.status_id = ? AND (o.next_call_at IS NULL OR o.next_call_at <= ?)) OR (o.status_id = ? AND o.hold_reason_id = ? AND o.advance_waived_at IS NULL AND o.advance_waiver_requested_at IS NULL AND NOT EXISTS (SELECT 1 FROM order_payments pc WHERE pc.order_id = o.id AND pc.payment_type = \'advance\' AND pc.status = \'pending_verification\' AND pc.counts_now = 0)))', 'bind' => [$s('record_verified'), $s('no_answer'), $now, $s('hold'), $advance], 'tone' => 'amber'],
            'again' => ['label' => __('Call again'), 'sql' => 'o.moderator_id IS NOT NULL AND o.status_id = ? AND o.next_call_at > ?', 'bind' => [$s('no_answer'), $now], 'tone' => 'orange'],
            'hold' => ['label' => __('On hold'), 'sql' => 'o.status_id = ? AND (NOT (COALESCE(o.hold_reason_id, 0) = ? AND o.advance_waived_at IS NULL) OR o.advance_waiver_requested_at IS NOT NULL OR EXISTS (SELECT 1 FROM order_payments pc WHERE pc.order_id = o.id AND pc.payment_type = \'advance\' AND pc.status = \'pending_verification\' AND pc.counts_now = 0))', 'bind' => [$s('hold'), $advance], 'tone' => 'red'],
            'send' => ['label' => __('Booking failed'), 'sql' => "o.status_id = ? AND o.booking_state IN ('none', 'failed')", 'bind' => [$s('confirmed')], 'tone' => 'green'],
            'packaging' => ['label' => __('Packaging'), 'sql' => "((o.status_id = ? AND o.booking_state = 'queued') OR o.status_id = ?)", 'bind' => [$s('confirmed'), $s('ready_for_packaging')], 'tone' => 'purple'],
            'packed' => ['label' => __('Packed'), 'sql' => 'o.status_id IN (?, ?)', 'bind' => [$s('packed'), $s('ready_for_pickup')], 'tone' => 'purple'],
            'shipped' => ['label' => __('With the courier'), 'sql' => 'o.status_id IN (?, ?)', 'bind' => [$s('handed_over'), $s('in_transit')], 'tone' => 'teal'],
        ];
    }

    /** Every stage, moving and finished (for the export). */
    public static function all(): array
    {
        $ids = fn (array $keys) => OrderStatus::idsFor($keys);
        $in = fn (array $keys) => ['sql' => 'o.status_id IN ('.implode(', ', array_fill(0, count($ids($keys)), '?')).')', 'bind' => $ids($keys)];

        return self::moving() + [
            'delivered' => ['label' => __('Delivered')] + $in(['delivered', 'partial_delivered']),
            'returned' => ['label' => __('Returned')] + $in(['returned']),
            'cancelled' => ['label' => __('Cancelled')] + $in(['cancelled']),
        ];
    }
}
