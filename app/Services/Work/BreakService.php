<?php

namespace App\Services\Work;

use App\Models\OrderStatus;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Orders\DeskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Breaks. While one is open the whole screen is covered and the server
 * refuses every action from that person until they press Start work.
 * Each break is one row (who, why, from, to, minutes), never edited except
 * by a logged admin correction.
 */
class BreakService
{
    public function __construct(private DeskService $desk, private WorkCalendar $calendar, private NotificationService $notifications) {}

    public function start(User $user, int $reasonId): void
    {
        if ($user->current_break_id) {
            throw ValidationException::withMessages(['reason_id' => __('You are already on a break.')]);
        }
        $reason = DB::table('status_reasons')->where('id', $reasonId)->where('reason_type', 'break')->where('is_active', true)->first();
        if (! $reason) {
            throw ValidationException::withMessages(['reason_id' => __('Choose a reason.')]);
        }

        DB::transaction(function () use ($user, $reason) {
            $id = DB::table('staff_breaks')->insertGetId([
                'user_id' => $user->id, 'reason_id' => $reason->id, 'counts_as_break' => (bool) $reason->counts_as_break, 'started_at' => now(),
            ]);
            DB::table('users')->where('id', $user->id)->update(['current_break_id' => $id]);
            $user->current_break_id = $id;

            // Customers should not wait for someone who is away: orders not called yet go back, without a penalty.
            $waiting = DB::table('orders')->where('moderator_id', $user->id)->where('channel', 'web')
                ->whereIn('status_id', OrderStatus::idsFor(['new', 'record_verified']))->pluck('id');
            foreach ($waiting as $orderId) {
                $this->desk->release($orderId, 'break');
            }
            // Returned No response orders stay, with their timer paused.
            DB::table('orders')->where('moderator_id', $user->id)->whereNotNull('action_due_at')->update(['action_due_at' => null]);
        });
    }

    public function end(User $user): void
    {
        if (! $user->current_break_id) {
            return;
        }
        $this->close((int) $user->current_break_id, now(), false);
        $user->current_break_id = null; // the timer starts again when they open an order
    }

    /**
     * Someone pressed Break and never came back: close it at the end of
     * their shift, marked "not closed by them", and tell managers.
     *
     * @return int breaks closed
     */
    public function autoClose(): int
    {
        $open = DB::table('staff_breaks as b')->join('users as u', 'u.id', '=', 'b.user_id')->whereNull('b.ended_at')
            ->get(['b.id', 'b.user_id', 'b.started_at', 'u.name']);
        $this->calendar->preload($open->pluck('user_id')->all());
        $closed = 0;

        foreach ($open as $b) {
            $start = Carbon::parse($b->started_at);
            $shift = $this->calendar->shift($b->user_id, $start->copy()->startOfDay());
            $end = $shift && $shift[1]->greaterThan($start) ? $shift[1] : $start->copy()->endOfDay();
            if (now()->lessThan($end)) {
                continue;
            }
            $this->close($b->id, $end, true);
            $closed++;
            $this->notifications->send('break_not_closed', __(':n went on a break at :t and did not come back', ['n' => $b->name, 't' => $start->format('g:i A')]), null, [
                'link' => route('attendance.index', ['date' => $start->toDateString()]),
                'user_ids' => array_merge($this->desk->managerIds(), [$b->user_id]),
            ]);
        }

        return $closed;
    }

    /** Minutes of counted breaks today, including a running one. */
    public function countedMinutesToday(int $userId): int
    {
        $rows = DB::table('staff_breaks')->where('user_id', $userId)->where('counts_as_break', true)
            ->where('started_at', '>=', now()->startOfDay())->get(['started_at', 'ended_at', 'minutes']);

        return (int) $rows->sum(fn ($r) => $r->ended_at ? (int) $r->minutes : Carbon::parse($r->started_at)->diffInMinutes(now(), true));
    }

    /** Admin correction of a break's end time, with a note (kept on the row and in the activity log). */
    public function correct(int $breakId, Carbon $endedAt, string $note, User $by): void
    {
        $b = DB::table('staff_breaks')->where('id', $breakId)->whereNotNull('ended_at')->first();
        abort_unless($b, 404);
        $start = Carbon::parse($b->started_at);
        if ($endedAt->lessThan($start)) {
            throw ValidationException::withMessages(['ended_at' => __('The end cannot be before the start.')]);
        }
        DB::table('staff_breaks')->where('id', $breakId)->update([
            'ended_at' => $endedAt, 'minutes' => (int) $start->diffInMinutes($endedAt, true), 'corrected_by' => $by->id, 'correction_note' => $note,
        ]);
        app(\App\Services\ActivityLogger::class)->log('staff_break.corrected', ['staff_break', $breakId],
            ['ended_at' => $b->ended_at, 'minutes' => $b->minutes], ['ended_at' => $endedAt->toDateTimeString(), 'note' => $note]);
    }

    private function close(int $breakId, Carbon $at, bool $auto): void
    {
        $b = DB::table('staff_breaks')->where('id', $breakId)->whereNull('ended_at')->first();
        if (! $b) {
            return;
        }
        DB::table('staff_breaks')->where('id', $breakId)->update([
            'ended_at' => $at, 'minutes' => (int) Carbon::parse($b->started_at)->diffInMinutes($at, true), 'auto_closed' => $auto,
        ]);
        DB::table('users')->where('id', $b->user_id)->where('current_break_id', $breakId)->update(['current_break_id' => null]);
    }
}
