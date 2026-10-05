<?php

namespace App\Services\Points;

use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * Awards points from admin-editable rules when a trigger happens. Each
 * order + rule + person is awarded once (unique index). "order_final"
 * points stay pending until the order ends; rules marked requires_delivery
 * are revoked if it ends without a delivery (fake confirms earn nothing).
 */
class PointsEngine
{
    /**
     * @param  array<string, mixed>  $context  condition inputs (see config/points.php fields)
     * @param  array{order_moderator?: ?int, actor?: ?int, packer?: ?int, previous_moderator?: ?int}  $people
     * @return int entries written
     */
    public function fire(string $trigger, ?Order $order, array $context, array $people): int
    {
        $final = $order ? OrderStatus::map()[$order->status_id] : null;
        $written = 0;

        foreach ($this->matching($trigger, $context) as $rule) {
            $userId = $people[$rule->recipient] ?? null;
            if (! $userId) {
                continue;
            }

            [$status, $revokeReason] = match (true) {
                $rule->settle_on === 'immediate' => ['final', null],
                $final && $final['final'] => $this->settledStatus((bool) $rule->requires_delivery, $final['key']),
                default => ['pending', null],
            };

            $points = $this->capped((float) $rule->points, $userId);

            $written += DB::table('point_ledger')->insertOrIgnore([
                'user_id' => $userId,
                'order_id' => $order?->id,
                'rule_id' => $rule->id,
                'trigger_key' => $trigger,
                'points' => $points,
                'rule_snapshot' => json_encode(['name' => $rule->name, 'points' => (float) $rule->points, 'recipient' => $rule->recipient,
                    'settle_on' => $rule->settle_on, 'requires_delivery' => (bool) $rule->requires_delivery]),
                'context' => json_encode($context),
                'status' => $status,
                'revoke_reason' => $revokeReason,
                'finalized_at' => $status === 'final' ? now() : null,
                'revoked_at' => $status === 'revoked' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $written;
    }

    /** Active rules of this trigger whose conditions all pass (used by fire and by "test on an order"). */
    public function matching(string $trigger, array $context): \Illuminate\Support\Collection
    {
        $rules = DB::table('point_rules')->where('trigger_key', $trigger)->where('is_active', true)->orderBy('sort_order')->get();
        if ($rules->isEmpty()) {
            return $rules;
        }
        $conditions = DB::table('point_rule_conditions')->whereIn('rule_id', $rules->pluck('id'))->get()->groupBy('rule_id');

        return $rules->filter(function ($rule) use ($conditions, $context) {
            foreach ($conditions[$rule->id] ?? [] as $c) {
                if (! $this->compare($context[$c->field] ?? null, $c->operator, $c->value)) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /** The order reached a final status: pending points become final, or revoked when delivery was required. */
    public function settle(Order $order): void
    {
        $key = OrderStatus::map()[$order->status_id]['key'];
        $rows = DB::table('point_ledger')->where('order_id', $order->id)->where('status', 'pending')->get(['id', 'rule_snapshot']);

        // The order came back after a delivery (courier corrected it): delivery points go.
        if (! in_array($key, ['delivered', 'partial_delivered'], true)) {
            DB::table('point_ledger')->where('order_id', $order->id)->where('status', 'final')
                ->whereIn('trigger_key', ['order_delivered', 'order_partial'])
                ->update(['status' => 'revoked', 'revoke_reason' => __('Delivery reversed'), 'revoked_at' => now(), 'updated_at' => now()]);
        }

        foreach ($rows as $row) {
            [$status, $reason] = $this->settledStatus((bool) (json_decode($row->rule_snapshot, true)['requires_delivery'] ?? false), $key);
            DB::table('point_ledger')->where('id', $row->id)->update([
                'status' => $status, 'revoke_reason' => $reason,
                'finalized_at' => $status === 'final' ? now() : null,
                'revoked_at' => $status === 'revoked' ? now() : null,
                'updated_at' => now(),
            ]);
        }
    }

    /** Minus points stop at the monthly cap so one bad week cannot wipe a person out. */
    private function capped(float $points, int $userId): float
    {
        $cap = (float) settings('points.monthly_negative_cap');
        if ($points >= 0 || $cap <= 0) {
            return $points;
        }
        $used = -(float) DB::table('point_ledger')->where('user_id', $userId)->where('status', '!=', 'revoked')
            ->where('points', '<', 0)->where('created_at', '>=', now()->startOfMonth())->sum('points');

        return -min(abs($points), max(0, $cap - $used));
    }

    /** @return array{0: string, 1: ?string} */
    private function settledStatus(bool $requiresDelivery, string $finalKey): array
    {
        $delivered = in_array($finalKey, ['delivered', 'partial_delivered'], true);

        return $requiresDelivery && ! $delivered ? ['revoked', __('Order ended :s, not delivered', ['s' => str_replace('_', ' ', $finalKey)])] : ['final', null];
    }

    private function compare(mixed $actual, string $op, string $expected): bool
    {
        if ($actual === null) {
            return false;
        }
        if (is_bool($actual)) {
            $want = in_array(strtolower($expected), ['1', 'true', 'yes'], true);

            return match ($op) { '=' => $actual === $want, '!=' => $actual !== $want, default => false };
        }
        if (! is_numeric($actual)) {
            return match ($op) { '=' => strtolower((string) $actual) === strtolower($expected), '!=' => strtolower((string) $actual) !== strtolower($expected), default => false };
        }
        $a = (float) $actual;
        $b = (float) $expected;

        return match ($op) { '>=' => $a >= $b, '<=' => $a <= $b, '>' => $a > $b, '<' => $a < $b, '=' => abs($a - $b) < 0.0001, '!=' => abs($a - $b) >= 0.0001 };
    }
}
