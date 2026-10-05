<?php

namespace App\Http\Controllers;

use App\Models\OrderStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Work\BreakService;
use App\Services\Work\WorkCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin views of the team: the control room (who holds what, what is stuck),
 * attendance and breaks (day and month), and each person's office days.
 */
class TeamController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    /** Control room: 6 grouped queries, no per-row lookups. */
    public function control(): View
    {
        $s = fn (string $key) => OrderStatus::idFor($key);
        $waitingIds = [$s('new'), $s('record_verified'), $s('no_answer')];
        $now = now();

        // Unassigned by age.
        $age = (array) DB::table('orders')->whereNull('moderator_id')->whereIn('status_id', $waitingIds)->selectRaw(
            'COUNT(*) as total,
             SUM(CASE WHEN COALESCE(queue_since, created_at) > ? THEN 1 ELSE 0 END) as fresh,
             SUM(CASE WHEN COALESCE(queue_since, created_at) <= ? AND COALESCE(queue_since, created_at) > ? THEN 1 ELSE 0 END) as mid,
             SUM(CASE WHEN COALESCE(queue_since, created_at) <= ? THEN 1 ELSE 0 END) as old,
             MIN(COALESCE(queue_since, created_at)) as oldest',
            [$now->copy()->subMinutes(5), $now->copy()->subMinutes(5), $now->copy()->subMinutes(15), $now->copy()->subMinutes(15)])->first();

        // Orders per stage and how long the oldest has been waiting there.
        $open = OrderStatus::idsFor(['new', 'record_verified', 'no_answer', 'hold', 'confirmed', 'ready_for_packaging', 'packed', 'ready_for_pickup', 'handed_over', 'in_transit']);
        $stages = DB::table('orders')->whereIn('status_id', $open)->groupBy('status_id')
            ->selectRaw('status_id, COUNT(*) as n, MIN(updated_at) as oldest')->get()->keyBy('status_id');

        // Per moderator.
        $load = DB::table('orders')->whereNotNull('moderator_id')->whereIn('status_id', [...$waitingIds, $s('hold'), $s('confirmed')])
            ->groupBy('moderator_id')->selectRaw(
                "moderator_id,
                 SUM(CASE WHEN status_id IN (?, ?) THEN 1 ELSE 0 END) as active,
                 SUM(CASE WHEN status_id = ? THEN 1 ELSE 0 END) as no_response,
                 SUM(CASE WHEN status_id = ? THEN 1 ELSE 0 END) as on_hold,
                 SUM(CASE WHEN status_id = ? THEN 1 ELSE 0 END) as to_send,
                 MIN(CASE WHEN status_id IN (?, ?) THEN assigned_at END) as oldest",
                [$s('new'), $s('record_verified'), $s('no_answer'), $s('hold'), $s('confirmed'), $s('new'), $s('record_verified')])->get()->keyBy('moderator_id');
        $released = DB::table('order_assignments')->where('ended_reason', 'timeout')->where('ended_at', '>=', $now->copy()->startOfDay())
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) as n')->pluck('n', 'user_id');
        $breaks = DB::table('staff_breaks')->where('started_at', '>=', $now->copy()->startOfDay())->where('counts_as_break', true)
            ->groupBy('user_id')->selectRaw('user_id, SUM(COALESCE(minutes, 0)) as minutes, COUNT(*) as n')->get()->keyBy('user_id');

        $people = User::where('is_active', true)
            ->where(fn ($q) => $q->whereIn('id', $load->keys())->orWhere('last_seen_at', '>=', $now->copy()->startOfDay()))
            ->orderBy('name')->get(['id', 'name', 'last_seen_at', 'current_break_id']);

        return view('desk.control', [
            'age' => $age,
            'stages' => $stages,
            'statuses' => OrderStatus::map(),
            'people' => $people,
            'load' => $load,
            'released' => $released,
            'breaks' => $breaks,
            'activeWindow' => (int) settings('desk.active_window_minutes'),
            'breakLimit' => (int) settings('work.break_limit_minutes'),
        ]);
    }

    /** Attendance and breaks: ?date=Y-m-d for one day, ?month=Y-m for the month summary. */
    public function attendance(Request $request, WorkCalendar $calendar): View
    {
        $limit = (int) settings('work.break_limit_minutes');

        if (preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))) {
            $month = $request->query('month');
            $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
            $end = $start->copy()->endOfMonth();

            $days = DB::table('work_days')->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->groupBy('user_id')
                ->selectRaw('user_id, COUNT(*) as days, SUM(CASE WHEN is_extra = 1 THEN 1 ELSE 0 END) as extra,
                    SUM(CASE WHEN is_extra = 1 AND extra_approved_at IS NOT NULL THEN 1 ELSE 0 END) as extra_approved')->get()->keyBy('user_id');
            // Per person per day first, so "days over the limit" can be counted.
            $perDay = DB::table('staff_breaks')->whereBetween('started_at', [$start, $end])->groupByRaw('user_id, DATE(started_at)')
                ->selectRaw('user_id, DATE(started_at) as d,
                    SUM(CASE WHEN counts_as_break = 1 THEN COALESCE(minutes, 0) ELSE 0 END) as rest,
                    SUM(CASE WHEN counts_as_break = 0 THEN COALESCE(minutes, 0) ELSE 0 END) as away,
                    SUM(CASE WHEN auto_closed = 1 THEN 1 ELSE 0 END) as not_closed, COUNT(*) as n')->get()->groupBy('user_id');
            $ids = array_unique(array_merge($days->keys()->all(), $perDay->keys()->all()));

            return view('team.attendance-month', [
                'month' => $month,
                'rows' => User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(function ($u) use ($days, $perDay, $limit) {
                    $b = $perDay[$u->id] ?? collect();
                    $worked = (int) ($days[$u->id]->days ?? 0);

                    return [
                        'id' => $u->id, 'name' => $u->name, 'days' => $worked,
                        'extra' => (int) ($days[$u->id]->extra ?? 0), 'extra_approved' => (int) ($days[$u->id]->extra_approved ?? 0),
                        'rest' => (int) $b->sum('rest'), 'away' => (int) $b->sum('away'), 'breaks' => (int) $b->sum('n'),
                        'avg' => $worked ? (int) round($b->sum('rest') / $worked) : 0,
                        'over' => $limit > 0 ? $b->filter(fn ($d) => $d->rest > $limit)->count() : 0,
                        'not_closed' => (int) $b->sum('not_closed'),
                    ];
                }),
                'limit' => $limit,
            ]);
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? $request->query('date') : today()->toDateString();
        $day = Carbon::parse($date);
        $work = DB::table('work_days')->where('work_date', $date)->get()->keyBy('user_id');
        $breaks = DB::table('staff_breaks as b')->leftJoin('status_reasons as r', 'r.id', '=', 'b.reason_id')
            ->whereBetween('b.started_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])->orderBy('b.started_at')
            ->get(['b.*', 'r.label_en as reason'])->groupBy('user_id');
        $users = User::whereIn('id', array_unique(array_merge($work->keys()->all(), $breaks->keys()->all())))->orderBy('name')->get(['id', 'name']);
        $calendar->preload($users->pluck('id')->all());

        return view('team.attendance', [
            'date' => $date,
            'users' => $users,
            'work' => $work,
            'breaks' => $breaks,
            'shifts' => $users->mapWithKeys(fn ($u) => [$u->id => $calendar->shift($u->id, $day->copy()->startOfDay())]),
            'limit' => $limit,
            'canEdit' => $request->user()->can('attendance.edit'),
        ]);
    }

    public function correctBreak(int $break, Request $request, BreakService $breaks): RedirectResponse
    {
        $data = $request->validate(['ended_at' => ['required', 'date_format:H:i'], 'note' => ['required', 'string', 'max:255']]);
        $row = DB::table('staff_breaks')->where('id', $break)->first();
        abort_unless($row, 404);
        $breaks->correct($break, Carbon::parse($row->started_at)->setTimeFromTimeString($data['ended_at']), $data['note'], $request->user());

        return back()->with('success', __('Break corrected.'));
    }

    /** An extra (off-day) working day counts for pay only once approved. */
    public function approveExtra(int $day, Request $request): RedirectResponse
    {
        $row = DB::table('work_days')->where('id', $day)->where('is_extra', true)->first();
        abort_unless($row, 404);
        $approved = $row->extra_approved_at === null;
        DB::table('work_days')->where('id', $day)->update([
            'extra_approved_by' => $approved ? $request->user()->id : null, 'extra_approved_at' => $approved ? now() : null,
        ]);
        $this->logger->log('work_day.extra_'.($approved ? 'approved' : 'unapproved'), ['work_day', $day], null, ['user_id' => $row->user_id, 'date' => $row->work_date]);

        return back()->with('success', $approved ? __('Extra day approved.') : __('Approval removed.'));
    }

    /** Office days and hours for one person. Sending no day at all returns them to the office default. */
    public function schedule(User $user, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['array'],
            'days.*.on' => ['nullable', 'boolean'],
            'days.*.start' => ['nullable', 'date_format:H:i'],
            'days.*.end' => ['nullable', 'date_format:H:i'],
        ]);
        $rows = [];
        foreach ($data['days'] ?? [] as $weekday => $d) {
            if (! empty($d['on']) && ! empty($d['start']) && ! empty($d['end']) && in_array((int) $weekday, range(0, 6), true)) {
                $rows[] = ['user_id' => $user->id, 'weekday' => (int) $weekday, 'start_time' => $d['start'], 'end_time' => $d['end']];
            }
        }
        DB::transaction(function () use ($user, $rows) {
            DB::table('work_schedules')->where('user_id', $user->id)->delete();
            DB::table('work_schedules')->insert($rows);
        });
        $this->logger->log('work_schedule.saved', ['user', $user->id], null, ['days' => $rows]);

        return back()->with('success', $rows ? __('Office days saved.') : __('Using the office default days now.'));
    }

    public function targets(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'delivered_count' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'delivery_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        $userId = $data['user_id'] ?? null;
        foreach (['delivered_count', 'delivery_rate'] as $metric) {
            $query = DB::table('kpi_targets')->where('metric', $metric)->when($userId, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id'));
            if (($data[$metric] ?? null) === null) {
                $query->delete();
            } elseif ($query->exists()) {
                $query->update(['target' => $data[$metric], 'updated_at' => now()]);
            } else {
                DB::table('kpi_targets')->insert(['user_id' => $userId, 'metric' => $metric, 'target' => $data[$metric], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->logger->log('kpi_target.saved', ['user', (int) $userId], null, $data);

        return back()->with('success', __('Target saved.'));
    }
}
