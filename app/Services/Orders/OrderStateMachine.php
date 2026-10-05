<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY way an order changes status. Every transition is checked against
 * order_status_transitions (permission, reason, system-only), core gates
 * that cannot be bypassed, and writes an order_events row (KPI source) plus
 * a status note so the timeline reads as one story.
 */
class OrderStateMachine
{
    public const SOURCES = ['user', 'rule', 'webhook', 'scan', 'system'];

    /** @var list<callable(Order, array, array, ?User): void> */
    private static array $listeners = [];

    /** Modules (notifications, points, CAPI) react to transitions here. */
    public static function listen(callable $listener): void
    {
        self::$listeners[] = $listener;
    }

    /** Called before registering, so a re-booted app never runs a listener twice. */
    public static function resetListeners(): void
    {
        self::$listeners = [];
    }

    /**
     * @param  string  $source  user | rule | webhook | scan | system
     */
    public function transition(Order $order, string $toKey, ?User $user, string $source = 'user', ?int $reasonId = null, ?string $note = null, ?int $expectedLock = null): Order
    {
        $map = OrderStatus::map();
        $to = $map[OrderStatus::idFor($toKey)];

        return DB::transaction(function () use ($order, $to, $user, $source, $reasonId, $note, $expectedLock, $map) {
            // Re-read under lock: two people acting on one order at once.
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($expectedLock !== null && $fresh->lock_version !== $expectedLock) {
                throw ValidationException::withMessages(['order' => __('This order was changed by someone else. Reload and try again.')]);
            }
            $from = $map[$fresh->status_id];

            $rule = DB::table('order_status_transitions')
                ->where('from_status_id', $from['id'])->where('to_status_id', $to['id'])->where('is_active', true)->first();
            if (! $rule) {
                throw ValidationException::withMessages(['status' => __('An order cannot go from :from to :to.', ['from' => $from['name'], 'to' => $to['name']])]);
            }
            if ($rule->system_only && $source === 'user') {
                throw ValidationException::withMessages(['status' => __(':to is set by the system (scan, booking or courier), not by hand.', ['to' => $to['name']])]);
            }
            if ($source === 'user' && $rule->permission_key && ! $user?->can($rule->permission_key)) {
                throw ValidationException::withMessages(['status' => __('You are not allowed to move orders to :to.', ['to' => $to['name']])]);
            }
            if ($source === 'user' && $from['key'] === 'hold' && ! in_array($to['key'], $this->holdExits($fresh), true)) {
                throw ValidationException::withMessages(['status' => __('This order was held after :to. It goes back to where it was, not an earlier step.', ['to' => $to['name']])]);
            }
            if (($rule->requires_reason || $to['requires_reason']) && ! $reasonId) {
                throw ValidationException::withMessages(['reason_id' => __('Choose a reason.')]);
            }

            // Core gate: nothing enters fulfilment without an active consignment.
            if ($to['group'] === 'fulfillment' && $from['group'] !== 'fulfillment' && ! $this->hasConsignment($fresh)) {
                throw ValidationException::withMessages(['status' => __('Book the courier first: no consignment ID yet.')]);
            }

            $previousAt = DB::table('order_events')->where('order_id', $fresh->id)->max('created_at');

            $fresh->status_id = $to['id'];
            $fresh->lock_version++;
            match ($to['key']) {
                'record_verified' => $fresh->verified_at ??= now(),
                'confirmed' => $fresh->confirmed_at ??= now(),
                default => null,
            };
            $fresh->hold_reason_id = $to['key'] === 'hold' ? $reasonId : null;
            if ($to['key'] !== 'hold') {
                $fresh->hold_expected_date = null;
            }
            $fresh->save();

            DB::table('order_events')->insert([
                'order_id' => $fresh->id,
                'from_status_id' => $from['id'],
                'to_status_id' => $to['id'],
                'source' => $source,
                'user_id' => $user?->id,
                'reason_id' => $reasonId,
                'seconds_since_previous' => $previousAt ? max(0, now()->diffInSeconds($previousAt, true)) : max(0, now()->diffInSeconds($fresh->created_at, true)),
                'created_at' => now(),
            ]);

            $reasonLabel = $reasonId ? DB::table('status_reasons')->where('id', $reasonId)->value('label_en') : null;
            DB::table('order_notes')->insert([
                'order_id' => $fresh->id,
                'note_type' => 'status',
                'body' => trim($from['name'].' → '.$to['name'].($reasonLabel ? ' · '.$reasonLabel : '').($note ? ' · '.$note : '')),
                'meta' => json_encode(['from' => $from['key'], 'to' => $to['key'], 'source' => $source, 'reason_id' => $reasonId]),
                'status_at_time_id' => $to['id'],
                'user_id' => $user?->id,
                'created_at' => now(),
            ]);

            foreach (self::$listeners as $listener) {
                $listener($fresh, $from, $to, $user);
            }

            $order->setRawAttributes($fresh->getAttributes(), true);

            return $order;
        });
    }

    /** @return list<array{key: ?string, name: string, color: string, requires_reason: bool, reason_type: string}> statuses this user may move the order to */
    public function allowedTargets(Order $order, User $user): array
    {
        $map = OrderStatus::map();
        $exits = $map[$order->status_id]['key'] === 'hold' ? $this->holdExits($order) : null;

        return DB::table('order_status_transitions')
            ->where('from_status_id', $order->status_id)->where('is_active', true)->where('system_only', false)
            ->get()
            ->filter(fn ($t) => ! $t->permission_key || $user->can($t->permission_key))
            ->filter(fn ($t) => $map[$t->to_status_id]['active'])
            ->filter(fn ($t) => $exits === null || in_array($map[$t->to_status_id]['key'], $exits, true))
            ->map(fn ($t) => [
                'key' => $map[$t->to_status_id]['key'],
                'name' => $map[$t->to_status_id]['name'],
                'color' => $map[$t->to_status_id]['color'],
                'requires_reason' => (bool) $t->requires_reason || $map[$t->to_status_id]['requires_reason'],
                'reason_type' => match ($map[$t->to_status_id]['key']) {
                    'cancelled' => 'cancel', 'hold' => 'hold', 'returned' => 'return', default => 'status',
                },
            ])->values()->all();
    }

    /**
     * Where a held order may go: back to the step it was held from, never an
     * earlier one. CN booked: packaging. Held after Confirmed: Confirmed (books the courier).
     * Held before or during the call: the call again, or Confirmed.
     */
    public function holdExits(Order $order, ?string $heldFrom = null): array
    {
        if ($this->hasConsignment($order)) {
            return ['ready_for_packaging', 'cancelled'];
        }
        $heldFrom ??= $this->heldFrom($order);

        return $heldFrom === 'confirmed' ? ['confirmed', 'cancelled'] : ['record_verified', 'confirmed', 'cancelled'];
    }

    /** The status the order was in when it was last put on hold. */
    public function heldFrom(Order $order): ?string
    {
        $from = DB::table('order_events')->where('order_id', $order->id)->where('to_status_id', OrderStatus::idFor('hold'))->orderByDesc('id')->value('from_status_id');

        return $from ? (OrderStatus::map()[$from]['key'] ?? null) : null;
    }

    private function hasConsignment(Order $order): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('shipments')
            && DB::table('shipments')->where('order_id', $order->id)->where('is_active', true)->whereNotNull('consignment_id')->exists();
    }
}
