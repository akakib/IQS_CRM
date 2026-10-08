<?php

namespace App\Services\Orders;

use App\Models\OrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How long an order really took in someone's hands. Time the customer made
 * us wait (No answer until the next try, On hold) is not the person's time,
 * so it is taken out: "net" time. Built from the status history that every
 * transition already writes; nothing extra is measured.
 */
class OrderTimeline
{
    /** Statuses where the order waits on the customer, not on us. */
    public const WAITING = ['no_answer', 'hold'];

    /** Seconds from $from to $to, minus the time spent waiting on the customer. */
    public function netSeconds(int $orderId, Carbon $from, Carbon $to): int
    {
        return $this->netFromEvents($this->events([$orderId])->get($orderId, collect()), $from, $to, $this->returns([$orderId])->get($orderId, collect()));
    }

    /**
     * When each No answer was due back (from the call notes), by order: the wait ends there,
     * not at the next call. Orders from before this was kept count the whole No answer as waiting.
     *
     * @return Collection<int, Collection<int, array{at: Carbon, back: Carbon}>>
     */
    public function returns(array $orderIds): Collection
    {
        return DB::table('order_notes')->whereIn('order_id', $orderIds)->where('note_type', 'call')->where('meta', 'like', '%returns_at%')
            ->orderBy('created_at')->get(['order_id', 'meta', 'created_at'])
            ->map(fn ($n) => ['order_id' => $n->order_id, 'at' => Carbon::parse($n->created_at), 'back' => Carbon::parse(json_decode($n->meta, true)['returns_at'])])
            ->groupBy('order_id');
    }

    /**
     * Status changes of these orders, oldest first, grouped by order (one query for many orders).
     *
     * @return Collection<int, Collection<int, object>>
     */
    public function events(array $orderIds): Collection
    {
        return DB::table('order_events')->whereIn('order_id', $orderIds)->orderBy('created_at')->orderBy('id')
            ->get(['order_id', 'from_status_id', 'to_status_id', 'user_id', 'created_at'])->groupBy('order_id');
    }

    /**
     * @param  Collection<int, object>  $events  this order's status changes, oldest first
     * @param  Collection<int, array{at: Carbon, back: Carbon}>|null  $returns  when each No answer was due back
     */
    public function netFromEvents(Collection $events, Carbon $from, Carbon $to, ?Collection $returns = null): int
    {
        $waiting = OrderStatus::idsFor(self::WAITING);
        $noAnswer = OrderStatus::idFor('no_answer');
        // Waiting in a stretch [$a, $b] with this status: all of it, except No answer only until it was due back.
        $wait = function ($status, Carbon $a, Carbon $b) use ($waiting, $noAnswer, $returns): int {
            if (! in_array($status, $waiting, true)) {
                return 0;
            }
            if ($status === $noAnswer && $returns) {
                $back = $returns->filter(fn ($r) => $r['at']->lte($a->copy()->addSecond()))->last()['back'] ?? null;
                if ($back) {
                    return $back->lte($a) ? 0 : (int) $a->diffInSeconds($back->lt($b) ? $back : $b, true);
                }
            }

            return (int) $a->diffInSeconds($b, true);
        };
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }
        // The status at $from: the last change before it.
        $status = $events->filter(fn ($e) => Carbon::parse($e->created_at)->lte($from))->last()?->to_status_id;
        $cursor = $from->copy();
        $waited = 0;
        foreach ($events as $e) {
            $at = Carbon::parse($e->created_at);
            if ($at->lte($from)) {
                continue;
            }
            if ($at->gt($to)) {
                break;
            }
            $waited += $wait($status, $cursor, $at);
            $cursor = $at;
            $status = $e->to_status_id;
        }
        $waited += $wait($status, $cursor, $to);

        return max(0, (int) $from->diffInSeconds($to, true) - $waited);
    }

    /**
     * The order's story for its page: came in, then for each person who held
     * it, given, opened, first action, and when it left the desk (net time).
     *
     * @return array{came: Carbon, turns: list<array{name: string, how: string, given: Carbon, opened: ?Carbon, acted: ?Carbon, ended: ?Carbon, ended_reason: ?string, sent: ?Carbon, net_seconds: ?int}>}
     */
    public function forOrder(int $orderId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['created_at']);
        $events = $this->events([$orderId])->get($orderId, collect());
        $returns = $this->returns([$orderId])->get($orderId, collect());
        $sentIds = OrderStatus::idsFor(['confirmed', 'cancelled']);
        $turns = DB::table('order_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.order_id', $orderId)->where('a.role', 'moderator')->orderBy('a.id')
            ->get(['u.name', 'a.how', 'a.started_at', 'a.first_opened_at', 'a.first_action_at', 'a.ended_at', 'a.ended_reason'])
            ->map(function ($a) use ($events, $returns, $sentIds) {
                $given = Carbon::parse($a->started_at);
                $end = $a->ended_at ? Carbon::parse($a->ended_at) : null;
                // Left the desk in this turn: confirmed (on to packaging) or cancelled.
                $sent = $events->first(fn ($e) => in_array($e->to_status_id, $sentIds, true) && Carbon::parse($e->created_at)->gte($given)
                    && (! $end || Carbon::parse($e->created_at)->lte($end)));
                $sentAt = $sent ? Carbon::parse($sent->created_at) : null;

                return [
                    'name' => $a->name, 'how' => $a->how, 'given' => $given,
                    'opened' => $a->first_opened_at ? Carbon::parse($a->first_opened_at) : null,
                    'acted' => $a->first_action_at ? Carbon::parse($a->first_action_at) : null,
                    'ended' => $end, 'ended_reason' => $a->ended_reason, 'sent' => $sentAt,
                    'sent_to' => $sent ? OrderStatus::map()[$sent->to_status_id]['key'] : null,
                    'net_seconds' => $sentAt ? $this->netFromEvents($events, $given, $sentAt, $returns) : null,
                ];
            })->all();

        return ['came' => Carbon::parse($order->created_at), 'turns' => $turns];
    }
}
