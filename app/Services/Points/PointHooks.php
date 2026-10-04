<?php

namespace App\Services\Points;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns what happened to an order into point triggers with their context.
 * Called from the state machine listener and from claim / reassign / edit /
 * escalation, so every point comes from a recorded event, never self-report.
 */
class PointHooks
{
    public function __construct(private PointsEngine $engine) {}

    /** status system_key => point trigger */
    public const STATUS_TRIGGERS = [
        'confirmed' => 'order_confirmed', 'packed' => 'order_packed', 'delivered' => 'order_delivered',
        'partial_delivered' => 'order_partial', 'returned' => 'order_returned', 'cancelled' => 'order_cancelled',
    ];

    public function transition(Order $order, array $from, array $to, ?User $actor): void
    {
        if ($trigger = self::STATUS_TRIGGERS[$to['key']] ?? null) {
            $this->engine->fire($trigger, $order, $this->context($trigger, $order, $actor), $this->people($trigger, $order, $actor));
            if ($trigger === 'order_confirmed') {
                $this->flagFastConfirm($order, $actor);
            }
        }

        if ($to['final']) {
            $this->engine->settle($order);
        }
    }

    /**
     * "Test on an order": the inputs each trigger would see for this order now,
     * and which rules would match. Writes nothing.
     *
     * @return array<string, array{context: array, rules: \Illuminate\Support\Collection}>
     */
    public function preview(Order $order): array
    {
        $out = [];
        $triggers = array_filter(['order_claimed', self::STATUS_TRIGGERS[OrderStatus::map()[$order->status_id]['key']] ?? null]);
        foreach ($triggers as $t) {
            $ctx = $this->context($t, $order, null);
            $out[$t] = ['context' => $ctx, 'rules' => $this->engine->matching($t, $ctx)];
        }

        return $out;
    }

    private function context(string $trigger, Order $order, ?User $actor): array
    {
        return match ($trigger) {
            'order_claimed' => ['minutes_waiting' => $this->minutesWaiting($order)],
            'order_confirmed' => ['minutes_since_claim' => $this->minutesSinceClaim($order), 'risky' => $this->risky($order), 'by_rule' => $actor === null && $this->confirmedByRule($order)],
            'order_packed' => ['minutes_since_release' => $this->minutesSinceRelease($order)],
            'order_delivered', 'order_partial' => ['risky' => $this->risky($order)],
            'order_returned' => ['risky' => $this->risky($order), 'blame' => $this->lastReasonBlame($order)],
            'order_cancelled' => ['risky' => $this->risky($order), 'blame' => $this->lastReasonBlame($order), 'advance_asked' => $this->advanceAsked($order)],
            default => [],
        };
    }

    private function people(string $trigger, Order $order, ?User $actor): array
    {
        return match ($trigger) {
            'order_packed' => ['packer' => $actor?->id],
            'order_delivered', 'order_partial', 'order_returned' => ['order_owner' => $order->owner_id, 'packer' => $this->packer($order)],
            default => ['order_owner' => $order->owner_id, 'actor' => $actor?->id],
        };
    }

    public function claimed(Order $order, User $user): void
    {
        $this->engine->fire('order_claimed', $order, $this->context('order_claimed', $order, $user), ['order_owner' => $user->id, 'actor' => $user->id]);
    }

    public function reassigned(Order $order, ?int $previousOwner, int $reasonId): void
    {
        $blame = DB::table('status_reasons')->where('id', $reasonId)->value('blame_stage') ?? 'none';
        $this->engine->fire('order_reassigned', $order, ['blame' => $blame], ['previous_owner' => $previousOwner, 'order_owner' => $order->owner_id]);
    }

    public function amended(Order $order, int $reasonId, bool $afterPack): void
    {
        $blame = DB::table('status_reasons')->where('id', $reasonId)->value('blame_stage') ?? 'none';
        // Counted once per order and rule (the ledger is unique per order + rule + person).
        $this->engine->fire('amendment', $order, ['blame' => $blame, 'after_pack' => $afterPack], ['order_owner' => $order->owner_id ?? $order->created_by]);
    }

    public function escalated(Order $order): void
    {
        $this->engine->fire('issue_escalated', $order, [], ['order_owner' => $order->owner_id]);
    }

    public function fakeStatus(?Order $order, int $userId): void
    {
        $this->engine->fire('fake_status', $order, [], ['actor' => $userId, 'order_owner' => $userId]);
    }

    /** Integrity: a web order confirmed seconds after taking it is not a real call. Manager reviews it. */
    private function flagFastConfirm(Order $order, ?User $actor): void
    {
        $minutes = $this->minutesSinceClaim($order);
        if ($actor && $minutes !== null && $order->channel === 'web' && $minutes < (float) settings('points.min_confirm_minutes')) {
            DB::table('integrity_flags')->insert([
                'order_id' => $order->id, 'user_id' => $actor->id, 'flag_type' => 'too_fast_confirm', 'detected_by' => 'system',
                'details' => __('Confirmed :s seconds after taking the order.', ['s' => (int) round($minutes * 60)]), 'status' => 'open',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function minutesWaiting(Order $order): float
    {
        $taken = DB::table('order_assignments')->where('order_id', $order->id)->orderBy('id')->value('started_at');

        return round($order->created_at->diffInSeconds($taken ? \Illuminate\Support\Carbon::parse($taken) : now(), true) / 60, 1);
    }

    private function minutesSinceClaim(Order $order): ?float
    {
        $claimedAt = DB::table('order_assignments')->where('order_id', $order->id)->where('user_id', $order->owner_id)->orderByDesc('id')->value('started_at');
        $confirmedAt = DB::table('order_events')->where('order_id', $order->id)->where('to_status_id', OrderStatus::idFor('confirmed'))->orderByDesc('id')->value('created_at');

        return $claimedAt ? round(\Illuminate\Support\Carbon::parse($claimedAt)->diffInSeconds($confirmedAt ? \Illuminate\Support\Carbon::parse($confirmedAt) : now(), true) / 60, 1) : null;
    }

    private function confirmedByRule(Order $order): bool
    {
        return DB::table('order_events')->where('order_id', $order->id)->where('to_status_id', OrderStatus::idFor('confirmed'))->orderByDesc('id')->value('source') === 'rule';
    }

    /** Not verified by the rules = the owner chose to send a risky order. */
    private function risky(Order $order): bool
    {
        $outcome = DB::table('verification_runs')->where('order_id', $order->id)->orderByDesc('id')->value('outcome');

        return in_array($outcome, ['manual_review', 'hold_for_advance'], true);
    }

    private function packer(Order $order): ?int
    {
        return DB::table('order_events')->where('order_id', $order->id)->where('to_status_id', OrderStatus::idFor('packed'))->orderByDesc('id')->value('user_id');
    }

    private function minutesSinceRelease(Order $order): ?float
    {
        $released = $order->batch_id ? DB::table('batches')->where('id', $order->batch_id)->value('released_at') : null;

        return $released ? round(now()->diffInSeconds($released, true) / 60, 1) : null;
    }

    private function lastReasonBlame(Order $order): string
    {
        $reasonId = DB::table('order_events')->where('order_id', $order->id)->orderByDesc('id')->value('reason_id');

        return ($reasonId ? DB::table('status_reasons')->where('id', $reasonId)->value('blame_stage') : null) ?? 'none';
    }

    private function advanceAsked(Order $order): bool
    {
        $advanceHold = DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'advance_wait')->value('id');

        return DB::table('order_payments')->where('order_id', $order->id)->exists()
            || DB::table('order_events')->where('order_id', $order->id)->where('reason_id', $advanceHold)->exists()
            || DB::table('order_notes')->where('order_id', $order->id)->whereIn('note_type', ['call', 'chat'])->where('body', 'like', '%advance%')->exists();
    }
}
