<?php

namespace App\Services\Work;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who works when. A person's own weekly rows (work_schedules) win; without
 * any, the office default from Settings applies. Timers and returns use this
 * so nothing falls due while its owner is off.
 * Rows have an effective_from date: a change today leaves past days on the
 * week they had (weekday 7 marks "office default from this date").
 */
class WorkCalendar
{
    /** @var array<int, array<string, array<int, array{0: string, 1: string}>>> user id => effective_from => weekday => [start, end] */
    private array $own = [];

    /** @var array<int, bool> users already looked up */
    private array $loaded = [];

    /** @var array<int, array{0: string, 1: string}>|null */
    private ?array $default = null;

    /** Load several people's schedules with one query. */
    public function preload(array $userIds): void
    {
        $missing = array_values(array_diff(array_unique(array_filter($userIds)), array_keys($this->loaded)));
        if ($missing === []) {
            return;
        }
        foreach ($missing as $id) {
            $this->loaded[$id] = true;
        }
        foreach (DB::table('work_schedules')->whereIn('user_id', $missing)->orderBy('effective_from')->get() as $row) {
            $from = substr((string) $row->effective_from, 0, 10);
            $this->own[$row->user_id][$from] ??= [];
            if ((int) $row->weekday !== 7) {
                $this->own[$row->user_id][$from][(int) $row->weekday] = [substr($row->start_time, 0, 5), substr($row->end_time, 0, 5)];
            }
        }
    }

    /** The person's own week on that date (null = the office default applies). */
    private function ownWeek(int $userId, ?Carbon $date): ?array
    {
        $this->preload([$userId]);
        $day = ($date ?? now())->toDateString();
        $week = null;
        foreach ($this->own[$userId] ?? [] as $from => $rows) {
            if ($from <= $day) {
                $week = $rows; // sets are in date order: the last one on or before the day wins
            }
        }

        return $week ?: null; // an empty set = back to the office default
    }

    /** @return array<int, array{0: string, 1: string}> weekday (0 = Sunday) => [start, end]; a missing day is off */
    public function week(?int $userId, ?Carbon $date = null): array
    {
        if ($userId && ($own = $this->ownWeek($userId, $date))) {
            return $own;
        }

        return $this->default ??= array_fill_keys(
            array_map('intval', explode(',', (string) settings('work.days'))),
            [(string) settings('work.start'), (string) settings('work.end')]
        );
    }

    public function hasOwnSchedule(int $userId): bool
    {
        return $this->ownWeek($userId, null) !== null;
    }

    public function isOffDay(?int $userId, Carbon $date): bool
    {
        return ! isset($this->week($userId, $date)[$date->dayOfWeek]);
    }

    /** @return array{0: Carbon, 1: Carbon}|null that day's shift (as it was set on that day) */
    public function shift(?int $userId, Carbon $date): ?array
    {
        $day = $this->week($userId, $date)[$date->dayOfWeek] ?? null;
        if (! $day) {
            return null;
        }
        $start = $date->copy()->setTimeFromTimeString($day[0]);
        $end = $date->copy()->setTimeFromTimeString($day[1]);

        return [$start, $end->lessThanOrEqualTo($start) ? $end->addDay() : $end];
    }

    public function isWorking(?int $userId, ?Carbon $at = null): bool
    {
        $at ??= now();
        // Today's shift, or last night's one that runs past midnight (8 PM to 9 AM is still on at 1 AM).
        foreach ([$at->copy()->startOfDay(), $at->copy()->subDay()->startOfDay()] as $day) {
            $shift = $this->shift($userId, $day);
            if ($shift !== null && $at->betweenIncluded($shift[0], $shift[1])) {
                return true;
            }
        }

        return false;
    }

    /** $at itself when inside a shift, otherwise the start of the next one. */
    public function nextWorkingMoment(?int $userId, Carbon $at): Carbon
    {
        if ($this->isWorking($userId, $at)) {
            return $at->copy();
        }
        for ($i = 0; $i < 9; $i++) {
            $shift = $this->shift($userId, $at->copy()->addDays($i)->startOfDay());
            if (! $shift) {
                continue;
            }
            if ($i === 0 && $at->betweenIncluded($shift[0], $shift[1])) {
                return $at->copy();
            }
            if ($shift[0]->greaterThan($at)) {
                return $shift[0];
            }
        }

        return $at->copy(); // no working day configured at all: do not block the flow
    }

    /** A deadline $minutes from now that never lands outside the person's shift. */
    public function deadline(?int $userId, int $minutes, ?Carbon $from = null): Carbon
    {
        $from ??= now();
        $due = $from->copy()->addMinutes($minutes);
        $moved = $this->nextWorkingMoment($userId, $due);

        return $moved->equalTo($due) ? $due : $moved->addMinutes($minutes);
    }
}
