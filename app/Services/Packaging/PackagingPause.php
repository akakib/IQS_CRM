<?php

namespace App\Services\Packaging;

use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pause packaging: a manager stops new orders reaching the packers' list (they can get confused by a long list).
 * Calls, confirming and courier booking go on as usual; the orders booked while paused stay Ready for packaging but
 * are kept out of the packers' list and their "To pack" count. The packers are not told. Resume: they arrive at once,
 * oldest first, and their packaging clock (packaging_sent_at, for the 30-minute pack points) starts then.
 */
class PackagingPause
{
    private ?object $row = null;

    private bool $loaded = false;

    public function active(): ?object
    {
        if (! $this->loaded) {
            $this->row = DB::table('packaging_pauses as p')->leftJoin('users as u', 'u.id', '=', 'p.paused_by')
                ->whereNull('p.resumed_at')->orderByDesc('p.id')->first(['p.id', 'p.paused_at', 'u.name as by']);
            $this->loaded = true;
        }

        return $this->row;
    }

    public function canControl(User $user): bool
    {
        return $user->can('packaging.manage') || $user->can('orders.approve');
    }

    /** SQL for "the packers may see this order" on an orders alias; empty when not paused. */
    public function visibleSql(string $alias = 'o'): string
    {
        $p = $this->active();

        return $p ? "NOT ({$alias}.status_id = ".(int) OrderStatus::idFor('ready_for_packaging')." AND {$alias}.packaging_sent_at >= '".$p->paused_at."')" : '';
    }

    public function heldCount(): int
    {
        return $this->active() ? $this->held()->count() : 0;
    }

    /** True when this order is one the packers must not see yet. */
    public function hidden(\App\Models\Order $order): bool
    {
        $p = $this->active();

        return $p && (int) $order->status_id === OrderStatus::idFor('ready_for_packaging') && $order->packaging_sent_at && $order->packaging_sent_at->gte($p->paused_at);
    }

    /** The orders booked since the pause began (only meaningful while paused). */
    private function held(): \Illuminate\Database\Query\Builder
    {
        return DB::table('orders')->where('status_id', OrderStatus::idFor('ready_for_packaging'))->where('packaging_sent_at', '>=', $this->active()->paused_at);
    }

    public function pause(User $by): void
    {
        if (! $this->active()) {
            DB::table('packaging_pauses')->insert(['paused_by' => $by->id, 'paused_at' => now()]);
            $this->loaded = false;
        }
    }

    /** @return int orders released to the packers */
    public function resume(User $by): int
    {
        $p = $this->active();
        if (! $p) {
            return 0;
        }

        return DB::transaction(function () use ($p, $by) {
            $held = $this->held()->update(['packaging_sent_at' => now()]);
            DB::table('packaging_pauses')->where('id', $p->id)->update(['resumed_by' => $by->id, 'resumed_at' => now(), 'held' => $held]);
            $this->loaded = false;

            return $held;
        });
    }
}
