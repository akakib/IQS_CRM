<?php

namespace App\Services\Reports;

use App\Services\Orders\OrderTimeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One day of work per person, without a countdown: for every turn of an
 * order in someone's hands (given that day), how long until they opened it,
 * until they first acted, and the net time until it left the desk.
 * Medians, not averages: one order held three days does not hide a good day.
 * Four queries for the whole day.
 */
class WorkTime
{
    public function __construct(private OrderTimeline $timeline, private FreeTime $free, private \App\Services\Work\WorkCalendar $calendar) {}

    /**
     * @return array{people: Collection<int, array<string, mixed>>, turns: Collection<int, Collection<int, array<string, mixed>>>}
     */
    public function day(Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $rows = DB::table('order_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')->join('orders as o', 'o.id', '=', 'a.order_id')
            ->where('a.role', 'moderator')->whereBetween('a.started_at', [$from, $to])->orderBy('a.started_at')
            ->get(['a.id', 'a.order_id', 'a.user_id', 'a.how', 'a.started_at', 'a.first_opened_at', 'a.first_action_at', 'a.ended_at', 'a.ended_reason',
                'u.name', 'u.photo_path', 'o.order_no', 'o.ship_name', 'o.status_id']);
        $ids = $rows->pluck('order_id')->unique()->values()->all();
        $events = $this->timeline->events($ids);
        $returns = $this->timeline->returns($ids);
        $sentIds = \App\Models\OrderStatus::idsFor(['confirmed', 'cancelled']);
        $statuses = \App\Models\OrderStatus::map();

        $turns = $rows->map(function ($a) use ($events, $returns, $sentIds, $statuses) {
            $given = Carbon::parse($a->started_at);
            $end = $a->ended_at ? Carbon::parse($a->ended_at) : null;
            $orderEvents = $events->get($a->order_id, collect());
            $sent = $orderEvents->first(fn ($e) => in_array($e->to_status_id, $sentIds, true) && Carbon::parse($e->created_at)->gte($given)
                && (! $end || Carbon::parse($e->created_at)->lte($end)));
            $sentAt = $sent ? Carbon::parse($sent->created_at) : null;
            $opened = $a->first_opened_at ? Carbon::parse($a->first_opened_at) : null;
            $acted = $a->first_action_at ? Carbon::parse($a->first_action_at) : null;

            return [
                'user_id' => $a->user_id, 'order_id' => $a->order_id, 'order_no' => $a->order_no, 'customer' => $a->ship_name,
                'how' => $a->how, 'given' => $given, 'opened' => $opened, 'acted' => $acted, 'sent' => $sentAt,
                'sent_to' => $sent ? $statuses[$sent->to_status_id]['key'] : null,
                'status' => $statuses[$a->status_id]['name'] ?? '',
                'ended_reason' => $a->ended_reason,
                'open_seconds' => $opened ? (int) $given->diffInSeconds($opened, true) : null,
                'start_seconds' => $acted ? (int) $given->diffInSeconds($acted, true) : null,
                'net_seconds' => $sentAt ? $this->timeline->netFromEvents($orderEvents, $given, $sentAt, $returns->get($a->order_id, collect())) : null,
            ];
        })->groupBy('user_id');

        $breakRows = DB::table('staff_breaks')->where('started_at', '<=', $to)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from))
            ->get(['user_id', 'started_at', 'ended_at', 'minutes', 'counts_as_break'])->groupBy('user_id');
        $breaks = $breakRows->map(fn ($b) => (int) $b->where('counts_as_break', true)->filter(fn ($x) => Carbon::parse($x->started_at)->between($from, $to))->sum('minutes'));

        // Everyone who works orders and was on shift that day, even with no order at all (free all day is worth seeing).
        $staff = \App\Models\User::where('is_active', true)->get(['id', 'name', 'photo_path'])
            ->filter(fn ($u) => $u->can('orders.take') && ! $u->isOwner());
        $this->calendar->preload($staff->pluck('id')->all());
        $staff = $staff->filter(fn ($u) => $turns->has($u->id) || ($day->lte(today()) && $this->calendar->shift($u->id, $from)));

        // Free time: turns that touch the day (also ones given before it), their history, breaks, orders waiting.
        $open = DB::table('order_assignments')->where('role', 'moderator')->whereIn('user_id', $staff->pluck('id'))->where('started_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from))->get(['user_id', 'order_id', 'started_at', 'ended_at'])->groupBy('user_id');
        $allIds = $open->flatten(1)->pluck('order_id')->unique()->values()->all();
        $allEvents = $this->timeline->events($allIds);
        $allReturns = $this->timeline->returns($allIds);
        $waitingSpans = $this->free->waiting($from, $to);
        $freeOf = fn (int $id) => $this->free->forPerson($id, $day, $this->free->busy($open->get($id, collect()), $allEvents, $allReturns, $breakRows->get($id, collect())), $waitingSpans);

        $people = $staff->map(function ($u) use ($turns, $breaks, $freeOf) {
            $mine = $turns->get($u->id, collect());
            $free = $freeOf($u->id);

            return [
                'id' => $u->id, 'name' => $u->name, 'photo' => $u->photo_path,
                'free_waiting' => $free['waiting'], 'free_nothing' => $free['nothing'],
                'turns' => $mine->count(),
                'confirmed' => $mine->where('sent_to', 'confirmed')->count(),
                'cancelled' => $mine->where('sent_to', 'cancelled')->count(),
                'given_back' => $mine->whereIn('ended_reason', ['idle', 'timeout'])->count(),
                'open' => $this->median($mine->pluck('open_seconds')),
                'start' => $this->median($mine->pluck('start_seconds')),
                'net' => $this->median($mine->where('sent_to', 'confirmed')->pluck('net_seconds')),
                'break_minutes' => (int) ($breaks[$u->id] ?? 0),
            ];
        })->sortBy('name')->values();

        return ['people' => $people, 'turns' => $turns];
    }

    private function median(Collection $values): ?int
    {
        $v = $values->filter(fn ($x) => $x !== null)->sort()->values();
        if ($v->isEmpty()) {
            return null;
        }
        $mid = intdiv($v->count(), 2);

        return (int) ($v->count() % 2 ? $v[$mid] : round(($v[$mid - 1] + $v[$mid]) / 2));
    }

    /** "4 min", "1 h 12 min", "35 s" */
    public static function span(?int $seconds): string
    {
        return match (true) {
            $seconds === null => '-',
            $seconds < 60 => $seconds.' s',
            $seconds < 3600 => intdiv($seconds, 60).' min',
            default => intdiv($seconds, 3600).' h '.intdiv($seconds % 3600, 60).' min',
        };
    }
}
