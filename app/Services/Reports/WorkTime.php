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
    public function __construct(private OrderTimeline $timeline) {}

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

        $breaks = DB::table('staff_breaks')->whereBetween('started_at', [$from, $to])->where('counts_as_break', true)
            ->groupBy('user_id')->selectRaw('user_id, SUM(COALESCE(minutes, 0)) as minutes')->pluck('minutes', 'user_id');

        $people = $rows->unique('user_id')->map(function ($a) use ($turns, $breaks) {
            $mine = $turns[$a->user_id];

            return [
                'id' => $a->user_id, 'name' => $a->name, 'photo' => $a->photo_path,
                'turns' => $mine->count(),
                'confirmed' => $mine->where('sent_to', 'confirmed')->count(),
                'cancelled' => $mine->where('sent_to', 'cancelled')->count(),
                'given_back' => $mine->whereIn('ended_reason', ['idle', 'timeout'])->count(),
                'open' => $this->median($mine->pluck('open_seconds')),
                'start' => $this->median($mine->pluck('start_seconds')),
                'net' => $this->median($mine->where('sent_to', 'confirmed')->pluck('net_seconds')),
                'break_minutes' => (int) ($breaks[$a->user_id] ?? 0),
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
