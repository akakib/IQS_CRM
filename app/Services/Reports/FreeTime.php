<?php

namespace App\Services\Reports;

use App\Models\OrderStatus;
use App\Services\Work\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Free time in a shift: no order in hand and not on a break. Split in two,
 * because they mean different things: free while website orders were
 * waiting for someone (time that could have been worked), and free while
 * nothing was waiting (no work to do; a staffing question, not a person's).
 *
 * Intervals are [start, end] in Unix seconds.
 */
class FreeTime
{
    public function __construct(private WorkCalendar $calendar) {}

    /** Website orders waiting for someone (nobody had taken them yet), merged, within [$from, $to]. One query. */
    public function waiting(Carbon $from, Carbon $to): array
    {
        $cancelled = OrderStatus::idFor('cancelled');
        $rows = DB::table('orders as o')->where('o.channel', 'web')->where('o.created_at', '<=', $to)
            ->selectRaw('o.created_at, o.status_id, o.updated_at, (SELECT MIN(a.started_at) FROM order_assignments a WHERE a.order_id = o.id) as first_taken')
            ->whereRaw('NOT EXISTS (SELECT 1 FROM order_assignments a2 WHERE a2.order_id = o.id AND a2.started_at < ?)', [$from])->get();
        $now = now()->getTimestamp();
        $spans = $rows->map(function ($r) use ($cancelled, $now) {
            $start = Carbon::parse($r->created_at)->getTimestamp();
            $end = $r->first_taken ? Carbon::parse($r->first_taken)->getTimestamp()
                : ((int) $r->status_id === $cancelled ? Carbon::parse($r->updated_at)->getTimestamp() : $now); // cancelled on the website before anyone took it

            return [$start, $end];
        })->all();

        return self::clip(self::union($spans), $from->getTimestamp(), $to->getTimestamp());
    }

    /**
     * Busy stretches of one person: orders in hand (from being given until it
     * left the to-do statuses; a No answer again from when it came back until
     * the next step) and breaks.
     *
     * @param  Collection<int, object>  $assignments  their turns (started_at, ended_at, order_id)
     * @param  Collection<int, Collection<int, object>>  $events  status changes by order
     * @param  Collection<int, Collection<int, array{at: Carbon, back: Carbon}>>  $returns  No answer due-back times by order
     * @param  Collection<int, object>  $breaks  their breaks (started_at, ended_at)
     */
    public function busy(Collection $assignments, Collection $events, Collection $returns, Collection $breaks): array
    {
        $todo = OrderStatus::idsFor(['new', 'record_verified']);
        $now = now()->getTimestamp();
        $spans = [];
        foreach ($assignments as $a) {
            $start = Carbon::parse($a->started_at)->getTimestamp();
            $end = $a->ended_at ? Carbon::parse($a->ended_at)->getTimestamp() : $now;
            $orderEvents = $events->get($a->order_id, collect());
            $left = $orderEvents->first(fn ($e) => Carbon::parse($e->created_at)->getTimestamp() > $start && ! in_array($e->to_status_id, $todo, true));
            $spans[] = [$start, $left ? min($end, Carbon::parse($left->created_at)->getTimestamp()) : $end];
            // Back from No answer: in hand again until the next step.
            foreach ($returns->get($a->order_id, collect()) as $r) {
                $back = $r['back']->getTimestamp();
                if ($back <= $start || $back >= $end || $back > $now) {
                    continue;
                }
                $next = $orderEvents->first(fn ($e) => Carbon::parse($e->created_at)->getTimestamp() > $back);
                $spans[] = [$back, min($end, $next ? Carbon::parse($next->created_at)->getTimestamp() : $now)];
            }
        }
        foreach ($breaks as $b) {
            $spans[] = [Carbon::parse($b->started_at)->getTimestamp(), $b->ended_at ? Carbon::parse($b->ended_at)->getTimestamp() : $now];
        }

        return self::union($spans);
    }

    /**
     * Free stretches in this person's shift on this day, each marked whether orders were waiting.
     *
     * @return array{waiting: int, nothing: int, segments: list<array{from: Carbon, to: Carbon, waiting: int, nothing: int}>}
     */
    public function forPerson(int $userId, Carbon $day, array $busy, array $waiting): array
    {
        $shift = $this->calendar->shift($userId, $day->copy()->startOfDay());
        $out = ['waiting' => 0, 'nothing' => 0, 'segments' => []];
        if (! $shift) {
            return $out; // off day: nothing is free time
        }
        $from = $shift[0]->getTimestamp();
        $to = min($shift[1]->getTimestamp(), now()->getTimestamp());
        foreach (self::subtract([[$from, $to]], $busy) as [$a, $b]) {
            if ($b - $a < 60) {
                continue; // a few seconds between two clicks is not free time
            }
            $w = self::length(self::clip($waiting, $a, $b));
            $out['waiting'] += $w;
            $out['nothing'] += ($b - $a) - $w;
            $out['segments'][] = ['from' => Carbon::createFromTimestamp($a, config('app.timezone')), 'to' => Carbon::createFromTimestamp($b, config('app.timezone')), 'waiting' => $w, 'nothing' => ($b - $a) - $w];
        }

        return $out;
    }

    public static function union(array $spans): array
    {
        usort($spans, fn ($x, $y) => $x[0] <=> $y[0]);
        $out = [];
        foreach ($spans as [$a, $b]) {
            if ($b <= $a) {
                continue;
            }
            if ($out && $a <= $out[count($out) - 1][1]) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
            } else {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /** $spans minus $cut (both merged). */
    public static function subtract(array $spans, array $cut): array
    {
        $out = [];
        foreach ($spans as [$a, $b]) {
            $cursor = $a;
            foreach ($cut as [$c, $d]) {
                if ($d <= $cursor || $c >= $b) {
                    continue;
                }
                if ($c > $cursor) {
                    $out[] = [$cursor, $c];
                }
                $cursor = max($cursor, $d);
            }
            if ($cursor < $b) {
                $out[] = [$cursor, $b];
            }
        }

        return $out;
    }

    public static function clip(array $spans, int $from, int $to): array
    {
        return array_values(array_filter(array_map(fn ($s) => [max($s[0], $from), min($s[1], $to)], $spans), fn ($s) => $s[1] > $s[0]));
    }

    public static function length(array $spans): int
    {
        return array_sum(array_map(fn ($s) => $s[1] - $s[0], $spans));
    }
}
