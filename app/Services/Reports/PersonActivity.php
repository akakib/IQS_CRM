<?php

namespace App\Services\Reports;

use App\Models\OrderStatus;
use App\Services\Orders\OrderTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything one person did on one day, in time order: sign-in, orders
 * given, opened, every status change and call they made, edits, breaks,
 * orders taken back from them, and the free stretches between (marked when
 * orders were waiting). Built from records already kept; nothing extra is
 * measured. A handful of queries for the whole day.
 */
class PersonActivity
{
    /** No answer this close before a break is flagged: it may have been pressed only to free the hands. */
    private const NO_ANSWER_BEFORE_BREAK = 120;

    public function __construct(private OrderTimeline $timeline, private FreeTime $free) {}

    /**
     * @return array{entries: list<array{at: Carbon, kind: string, text: string, order_id: ?int, flag: ?string, until: ?Carbon}>, free: array{waiting: int, nothing: int}}
     */
    public function day(int $userId, Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $statuses = OrderStatus::map();
        $entries = [];
        $add = function (Carbon $at, string $kind, string $text, ?int $orderId = null, ?string $flag = null, ?Carbon $until = null) use (&$entries) {
            $entries[] = compact('at', 'kind', 'text', 'flag', 'until') + ['order_id' => $orderId];
        };

        foreach (DB::table('activity_log')->where('actor_id', $userId)->where('action', 'auth.login')->whereBetween('created_at', [$from, $to])->pluck('created_at') as $t) {
            $add(Carbon::parse($t), 'login', __('Signed in'));
        }

        // Turns in hand that touch this day (given today, or still open from before).
        $turns = DB::table('order_assignments as a')->join('orders as o', 'o.id', '=', 'a.order_id')->leftJoin('users as b', 'b.id', '=', 'a.assigned_by')
            ->where('a.user_id', $userId)->where('a.role', 'moderator')->where('a.started_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('a.ended_at')->orWhere('a.ended_at', '>=', $from))
            ->orderBy('a.started_at')->get(['a.order_id', 'a.how', 'a.started_at', 'a.first_opened_at', 'a.ended_at', 'a.ended_reason', 'o.order_no', 'b.name as by_name']);
        $given = $turns->filter(fn ($a) => Carbon::parse($a->started_at)->between($from, $to))
            ->groupBy(fn ($a) => Carbon::parse($a->started_at)->format('Y-m-d H:i').'|'.$a->how);
        foreach ($given as $group) {
            $first = $group->first();
            $list = $group->pluck('order_no')->join(', ');
            $add(Carbon::parse($first->started_at), 'given', match ($first->how) {
                'auto' => __('Given by the system: :l', ['l' => $list]),
                'reassigned' => __('Handed over by :n: :l', ['n' => $first->by_name ?? __('admin'), 'l' => $list]),
                default => __('Took :l', ['l' => $list]),
            }, $group->count() === 1 ? (int) $first->order_id : null);
        }
        foreach ($turns as $a) {
            if ($a->first_opened_at && Carbon::parse($a->first_opened_at)->between($from, $to)) {
                $add(Carbon::parse($a->first_opened_at), 'opened', __('Opened :o', ['o' => $a->order_no]), (int) $a->order_id);
            }
            if ($a->ended_at && in_array($a->ended_reason, ['idle', 'timeout', 'break', 'reassigned'], true) && Carbon::parse($a->ended_at)->between($from, $to)) {
                $add(Carbon::parse($a->ended_at), $a->ended_reason === 'reassigned' ? 'given' : 'taken_back', match ($a->ended_reason) {
                    'idle' => __(':o taken back: nothing done for :m minutes', ['o' => $a->order_no, 'm' => settings('desk.idle_return_minutes')]),
                    'timeout' => __(':o taken back: timer ran out', ['o' => $a->order_no]),
                    'break' => __(':o back to New: went on a break', ['o' => $a->order_no]),
                    default => __(':o handed to someone else', ['o' => $a->order_no]),
                }, (int) $a->order_id, in_array($a->ended_reason, ['idle', 'timeout'], true) ? 'red' : null);
            }
        }

        // What they did on orders: status changes and calls, edits.
        $events = DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')->leftJoin('status_reasons as r', 'r.id', '=', 'e.reason_id')
            ->where('e.user_id', $userId)->whereBetween('e.created_at', [$from, $to])->orderBy('e.id')
            ->get(['e.order_id', 'e.from_status_id', 'e.to_status_id', 'e.created_at', 'o.order_no', 'r.label_en as reason']);
        foreach ($events as $e) {
            $add(Carbon::parse($e->created_at), 'status', trim(($e->from_status_id === null
                ? __('Created :o (:b)', ['o' => $e->order_no, 'b' => $statuses[$e->to_status_id]['name'] ?? '?'])
                : __(':o: :a → :b', ['o' => $e->order_no, 'a' => $statuses[$e->from_status_id]['name'] ?? '?', 'b' => $statuses[$e->to_status_id]['name'] ?? '?']))
                .($e->reason ? ' · '.$e->reason : '')), (int) $e->order_id);
        }
        $notes = DB::table('order_notes as n')->join('orders as o', 'o.id', '=', 'n.order_id')
            ->where('n.user_id', $userId)->whereIn('n.note_type', ['call', 'amendment', 'manual'])->whereBetween('n.created_at', [$from, $to])->orderBy('n.id')
            ->get(['n.order_id', 'n.note_type', 'n.body', 'n.created_at', 'o.order_no']);
        foreach ($notes as $n) {
            $add(Carbon::parse($n->created_at), $n->note_type, $n->order_no.': '.mb_strimwidth($n->body, 0, 140, '…'), (int) $n->order_id);
        }

        // Breaks, and a No answer pressed just before one.
        $breaks = DB::table('staff_breaks as b')->leftJoin('status_reasons as r', 'r.id', '=', 'b.reason_id')->where('b.user_id', $userId)
            ->where('b.started_at', '<=', $to)->where(fn ($q) => $q->whereNull('b.ended_at')->orWhere('b.ended_at', '>=', $from))
            ->orderBy('b.started_at')->get(['b.started_at', 'b.ended_at', 'b.minutes', 'b.auto_closed', 'b.counts_as_break', 'r.label_en as reason']);
        $noAnswer = OrderStatus::idFor('no_answer');
        foreach ($breaks as $b) {
            $start = Carbon::parse($b->started_at);
            $justBefore = $events->first(fn ($e) => (int) $e->to_status_id === $noAnswer
                && Carbon::parse($e->created_at)->lte($start) && Carbon::parse($e->created_at)->diffInSeconds($start, true) <= self::NO_ANSWER_BEFORE_BREAK);
            $add($start, 'break', trim(__('Break: :r', ['r' => $b->reason ?? __('no reason')]).($b->counts_as_break ? '' : ' ('.__('not counted as a break').')')
                .($b->ended_at ? ' · '.__(':m min', ['m' => $b->minutes]) : ' · '.__('still on it'))
                .($b->auto_closed ? ' · '.__('never pressed Start work') : '')),
                null, $justBefore ? __('No answer on :o just before the break', ['o' => $justBefore->order_no]) : ($b->auto_closed ? 'red' : null),
                $b->ended_at ? Carbon::parse($b->ended_at) : null);
        }

        // Free stretches between all of that.
        $orderIds = $turns->pluck('order_id')->unique()->values()->all();
        $busy = $this->free->busy($turns, $this->timeline->events($orderIds), $this->timeline->returns($orderIds), $breaks);
        $free = $this->free->forPerson($userId, $day, $busy, $this->free->waiting($from, $to));
        foreach ($free['segments'] as $s) {
            $add($s['from'], 'free', $s['waiting']
                ? __('Free :t · orders were waiting for :w of it', ['t' => WorkTime::span($s['waiting'] + $s['nothing']), 'w' => WorkTime::span($s['waiting'])])
                : __('Free :t · nothing was waiting', ['t' => WorkTime::span($s['nothing'])]), null, $s['waiting'] ? 'red' : null, $s['to']);
        }

        usort($entries, fn ($x, $y) => [$x['at']->getTimestamp(), $x['kind'] === 'free' ? 1 : 0] <=> [$y['at']->getTimestamp(), $y['kind'] === 'free' ? 1 : 0]);

        return ['entries' => $entries, 'free' => ['waiting' => $free['waiting'], 'nothing' => $free['nothing']]];
    }
}
