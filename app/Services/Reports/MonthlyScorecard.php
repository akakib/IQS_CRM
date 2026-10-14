<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One month per person, side by side with the team: work (orders,
 * delivered, delivery and return rate), speed (net time, free while
 * orders waited, given back), points, and money (profit from their
 * delivered orders against salary + bonus). Each number is compared with
 * the team's middle value: green better, red worse (15% either side),
 * yellow around it. Built from the reports that already exist.
 */
class MonthlyScorecard
{
    /** metric => true when higher is better */
    public const METRICS = [
        'delivered' => true, 'delivery_rate' => true, 'return_rate' => false, 'net' => false,
        'free_waiting' => false, 'given_back' => false, 'points' => true, 'per_taka' => true,
    ];

    private const BAND = 0.15;

    public function __construct(private KpiScorecard $kpi, private WorkTime $work, private OrderProfit $profit) {}

    /** A closed month: what was saved when it was closed (later returns or revoked points do not change it). */
    public function closed(Carbon $month): ?array
    {
        $row = DB::table('scorecard_closes as c')->leftJoin('users as u', 'u.id', '=', 'c.closed_by')->where('c.month', $month->copy()->startOfMonth()->toDateString())
            ->first(['c.snapshot', 'c.closed_at', 'u.name as closer']);
        if (! $row) {
            return null;
        }
        $s = json_decode($row->snapshot, true);

        return ['rows' => collect($s['rows']), 'team' => $s['team'], 'from' => Carbon::parse($s['from']), 'to' => Carbon::parse($s['to']),
            'unassigned' => (float) ($s['unassigned'] ?? 0), 'closedAt' => Carbon::parse($row->closed_at), 'closedBy' => $row->closer];
    }

    /** Save the month as it stands now. */
    public function close(Carbon $month, int $by): void
    {
        $data = $this->month($month);
        DB::table('scorecard_closes')->updateOrInsert(['month' => $month->copy()->startOfMonth()->toDateString()], [
            'snapshot' => json_encode(['rows' => $data['rows']->values(), 'team' => $data['team'], 'from' => $data['from']->toDateString(),
                'to' => $data['to']->toDateString(), 'unassigned' => $data['unassigned']]),
            'closed_by' => $by, 'closed_at' => now(),
        ]);
    }

    /** @return array{rows: Collection<int, array<string, mixed>>, team: array<string, ?float>, from: Carbon, to: Carbon} */
    public function month(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->min(today()->endOfDay());

        $kpi = collect($this->kpi->build($from->toDateString(), $to->toDateString())['rows'])->keyBy('user_id');
        $work = $this->work->period($from, $to)['people']->keyBy('id');
        $points = DB::table('point_ledger')->where('status', 'final')->whereBetween('created_at', [$from, $to->copy()->endOfDay()])
            ->groupBy('user_id')->selectRaw('user_id, SUM(points) as p')->pluck('p', 'user_id');
        // Profit before ads: staff do not decide the ad spend.
        $profit = collect($this->profit->grouped('moderator', $from->toDateString(), $to->toDateString(), null, 1000)->items())
            ->mapWithKeys(fn ($r) => [(int) $r->g => (float) $r->profit + (float) $r->ad_cost]);
        $salary = DB::table('staff_salaries as s')->where('s.from_month', '<=', $from->toDateString())
            ->whereRaw('s.from_month = (SELECT MAX(s2.from_month) FROM staff_salaries s2 WHERE s2.user_id = s.user_id AND s2.from_month <= ?)', [$from->toDateString()])
            ->pluck('s.monthly_salary', 's.user_id');
        $bonus = DB::table('staff_bonuses')->where('month', $from->toDateString())->get(['user_id', 'amount', 'note'])->keyBy('user_id');

        // Owners watch; they are not scored against the team.
        $people = \App\Models\User::whereIn('id', $work->keys()->merge($kpi->keys())->unique())->get(['id', 'name', 'created_at', 'left_at'])->reject(fn ($u) => $u->isOwner());
        $ids = $people->pluck('id');
        $names = $people->pluck('name', 'id');
        // The days of the month each person was with us: salary and target are for those days only.
        $monthEnd = $month->copy()->endOfMonth();
        $daysIn = $month->daysInMonth;
        $here = $people->mapWithKeys(function ($u) use ($from, $monthEnd, $daysIn) {
            $start = $u->created_at ? max($from->copy(), $u->created_at->copy()->startOfDay()) : $from->copy();
            $end = $u->left_at ? min($monthEnd->copy(), \Illuminate\Support\Carbon::parse($u->left_at)->endOfDay()) : $monthEnd->copy();

            return [$u->id => max(0, min($daysIn, (int) $start->diffInDays($end->copy()->startOfDay(), false) + 1))];
        });
        // Everyone has a target (their own, else the default), even with no ended order yet.
        $targets = KpiScorecard::targetsFor($from->toDateString()); // the targets of that month, not today's
        $targetOf = fn (int $id, string $metric) => $targets[$id][$metric] ?? $targets[0][$metric] ?? null;
        // Managers and owners may confirm a few orders: they are shown, not part of the team middle.
        $leads = $people->filter(fn ($u) => $u->can('orders.reassign'))->pluck('id')->all();
        $rows = $ids->map(function ($id) use ($kpi, $work, $points, $profit, $salary, $bonus, $names, $here, $daysIn, $leads, $targetOf) {
            $k = $kpi->get($id, []);
            $w = $work->get($id, []);
            $days = (int) ($here[$id] ?? $daysIn);
            $share = $daysIn ? $days / $daysIn : 1;
            $monthly = isset($salary[$id]) ? (float) $salary[$id] : null;
            $paid = $monthly === null ? null : round($monthly * $share, 2);
            $monthTarget = $targetOf((int) $id, 'delivered_count');
            $target = $monthTarget !== null ? (int) round((float) $monthTarget * $share) : null;
            $pay = (float) ($paid ?? 0) + (float) ($bonus[$id]->amount ?? 0);
            $made = $profit[$id] ?? 0.0;

            return [
                'id' => (int) $id, 'name' => $names[$id] ?? '#'.$id,
                'orders' => (int) ($w['turns'] ?? $k['taken'] ?? 0),
                'outside_confirmed' => (int) ($w['outside_confirmed'] ?? 0),
                'outside_made' => (int) ($w['outside_made'] ?? 0),
                'delivered' => (int) ($k['delivered'] ?? 0),
                'delivery_rate' => $k['delivery_rate'] ?? null,
                'return_rate' => $k['return_rate'] ?? null,
                'target_count' => $target,
                'target_done' => $target ? (int) round(((int) ($k['delivered'] ?? 0)) * 100 / $target) : null,
                'target_rate' => ($r = $targetOf((int) $id, 'delivery_rate')) !== null ? (float) $r : null,
                'days' => $days, 'days_in_month' => $daysIn, 'lead' => in_array($id, $leads, true),
                'net' => $w['net'] ?? null,
                'free_waiting' => (int) ($w['free_waiting'] ?? 0),
                'given_back' => (int) ($w['given_back'] ?? 0),
                'points' => (float) ($points[$id] ?? 0),
                'profit' => $made,
                'salary' => $paid,
                'monthly_salary' => $monthly,
                'bonus' => isset($bonus[$id]) ? (float) $bonus[$id]->amount : null,
                'bonus_note' => $bonus[$id]->note ?? null,
                'pay' => $pay,
                'per_taka' => $pay > 0 ? round($made / $pay, 2) : null,
            ];
        })->values();

        // The team's middle value for each number, then a colour for each person.
        $team = [];
        $trial = (bool) settings('points.trial_mode');
        $core = $rows->where('lead', false);
        foreach (array_keys(self::METRICS) as $m) {
            $team[$m] = $this->median(($core->isNotEmpty() ? $core : $rows)->pluck($m)->filter(fn ($v) => $v !== null));
        }
        $rows = $rows->map(function ($r) use ($team, $trial) {
            $r['lights'] = [];
            foreach (self::METRICS as $m => $higherBetter) {
                // Trial month: points are shown but do not colour anyone.
                $r['lights'][$m] = $m === 'points' && $trial ? null : $this->light($r[$m], $team[$m], $higherBetter);
            }
            $reds = count(array_keys($r['lights'], 'red', true));
            $greens = count(array_keys($r['lights'], 'green', true));
            $r['overall'] = $reds >= 3 ? 'red' : ($greens >= 3 && $reds === 0 ? 'green' : 'yellow');

            return $r;
        })->sortByDesc(fn ($r) => [$r['overall'] === 'green' ? 2 : ($r['overall'] === 'yellow' ? 1 : 0), $r['delivered']])->values();

        // Orders confirmed by a rule with nobody holding them: their profit is nobody's, shown on its own so the people add up.
        return ['rows' => $rows, 'team' => $team, 'from' => $from, 'to' => $to, 'unassigned' => (float) ($profit[0] ?? 0)];
    }

    private function light(int|float|null $value, ?float $team, bool $higherBetter): ?string
    {
        if ($value === null || $team === null) {
            return null;
        }
        if ($team == 0) {
            return $value == 0 ? 'yellow' : ($higherBetter ? 'green' : 'red');
        }
        $diff = ($value - $team) / abs($team);
        if (! $higherBetter) {
            $diff = -$diff;
        }

        return $diff > self::BAND ? 'green' : ($diff < -self::BAND ? 'red' : 'yellow');
    }

    private function median(Collection $values): ?float
    {
        $v = $values->sort()->values();
        if ($v->isEmpty()) {
            return null;
        }
        $mid = intdiv($v->count(), 2);

        return (float) ($v->count() % 2 ? $v[$mid] : ($v[$mid - 1] + $v[$mid]) / 2);
    }
}
