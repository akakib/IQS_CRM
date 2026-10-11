<?php

namespace App\Services\Work;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Orders\DeskService;
use App\Services\Orders\OrderService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Someone leaves (deactivated): their unfinished work must not sit under a
 * name nobody uses. Orders not called yet go back to New (the desk hands them
 * to whoever is free). Orders already talked about (No answer, Hold, waiting
 * for advance, Confirmed but not booked) and open delivery issues go to ONE
 * person, so the customer talks to someone who can read the whole story.
 * Orders sent to packaging stay in their name: credit and blame stay right.
 */
class StaffHandover
{
    public function __construct(private DeskService $desk, private OrderService $orders, private BreakService $breaks, private ChatService $chat) {}

    /** @return array{fresh: list<int>, talking: list<int>, issues: list<int>} */
    public function work(User $user): array
    {
        $ids = fn (array $keys) => DB::table('orders')->where('moderator_id', $user->id)->whereIn('status_id', OrderStatus::idsFor($keys))->pluck('id')->map(fn ($i) => (int) $i)->all();

        return [
            'fresh' => $ids(['new', 'record_verified']),
            'talking' => $ids(['no_answer', 'hold', 'confirmed']),
            'issues' => DB::table('delivery_issues')->where('assigned_to', $user->id)->whereNull('resolved_at')->pluck('id')->map(fn ($i) => (int) $i)->all(),
        ];
    }

    /** Who can take it over, the least loaded first. */
    public function people(User $leaving): Collection
    {
        $open = OrderStatus::idsFor(['new', 'record_verified', 'no_answer', 'hold', 'confirmed']);
        $load = DB::table('orders')->whereIn('status_id', $open)->whereNotNull('moderator_id')->groupBy('moderator_id')->pluck(DB::raw('count(*)'), 'moderator_id');

        return User::where('is_active', true)->where('id', '!=', $leaving->id)->orderBy('name')->get()
            ->filter(fn (User $u) => ! $u->isOwner() && $u->can('orders.take'))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'load' => (int) ($load[$u->id] ?? 0)])
            ->sortBy('load')->values();
    }

    /** @return array{fresh: int, talking: int, issues: int} what moved */
    public function handOver(User $leaving, ?User $to, User $by): array
    {
        $work = $this->work($leaving);
        if (($work['talking'] || $work['issues']) && ! $to) {
            throw ValidationException::withMessages(['give_to' => __('Choose who takes over their orders.')]);
        }
        if ($to && ($to->is($leaving) || ! $to->is_active)) {
            throw ValidationException::withMessages(['give_to' => __('Choose someone who is still working.')]);
        }

        DB::transaction(function () use ($leaving, $to, $by, $work) {
            $this->breaks->end($leaving);
            $this->chat->stop($leaving);
            foreach ($work['fresh'] as $id) {
                $this->desk->release($id, 'left');
            }
            $reason = (int) DB::table('status_reasons')->where('reason_type', 'reassign')->where('system_key', 'on_leave')->value('id');
            foreach ($work['talking'] as $id) {
                $this->orders->reassign(Order::findOrFail($id), $by, $to, $reason);
            }
            if ($work['issues']) {
                DB::table('delivery_issues')->whereIn('id', $work['issues'])->update(['assigned_to' => $to->id, 'updated_at' => now()]);
            }
        });

        return ['fresh' => count($work['fresh']), 'talking' => count($work['talking']), 'issues' => count($work['issues'])];
    }
}
