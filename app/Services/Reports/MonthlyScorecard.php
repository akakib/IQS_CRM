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

    /** @return array{rows: Collection<int, array<string, mixed>>, team: array<string, ?float>, from: Carbon, to: Carbon} */
    public function month(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->min(today()->endOfDay());

        $kpi = collect($this->kpi->build($from->toDateString(), $to->toDateString())['rows'])->keyBy('user_id');
        $work = $this->work->period($from, $to)['people']->keyBy('id');
        $points = DB::table('point_ledger')->where('status', '!=', 'revoked')->whereBetween('created_at', [$from, $to->copy()->endOfDay()])
            ->groupBy('user_id')->selectRaw('user_id, SUM(points) as p')->pluck('p', 'user_id');
        // Profit before ads: staff do not decide the ad spend.
        $profit = collect($this->profit->grouped('moderator', $from->toDateString(), $to->toDateString(), null, 1000)->items())
            ->mapWithKeys(fn ($r) => [(int) $r->g => (float) $r->profit + (float) $r->ad_cost]);
        $salary = DB::table('staff_salaries as s')->where('s.from_month', '<=', $from->toDateString())
            ->whereRaw('s.from_month = (SELECT MAX(s2.from_month) FROM staff_salaries s2 WHERE s2.user_id = s.user_id AND s2.from_month <= ?)', [$from->toDateString()])
            ->pluck('s.monthly_salary', 's.user_id');
        $bonus = DB::table('staff_bonuses')->where('month', $from->toDateString())->get(['user_id', 'amount', 'note'])->keyBy('user_id');

        // Owners watch; they are not scored against the team.
        $people = \App\Models\User::whereIn('id', $work->keys()->merge($kpi->keys())->unique())->get(['id', 'name'])->reject(fn ($u) => $u->isOwner());
        $ids = $people->pluck('id');
        $names = $people->pluck('name', 'id');
        $rows = $ids->map(function ($id) use ($kpi, $work, $points, $profit, $salary, $bonus, $names) {
            $k = $kpi->get($id, []);
            $w = $work->get($id, []);
            $pay = (float) ($salary[$id] ?? 0) + (float) ($bonus[$id]->amount ?? 0);
            $made = $profit[$id] ?? 0.0;

            return [
                'id' => (int) $id, 'name' => $names[$id] ?? '#'.$id,
                'orders' => (int) ($w['turns'] ?? $k['taken'] ?? 0),
                'delivered' => (int) ($k['delivered'] ?? 0),
                'delivery_rate' => $k['delivery_rate'] ?? null,
                'return_rate' => $k['return_rate'] ?? null,
                'target_count' => $k['target_count'] ?? null,
                'net' => $w['net'] ?? null,
                'free_waiting' => (int) ($w['free_waiting'] ?? 0),
                'given_back' => (int) ($w['given_back'] ?? 0),
                'points' => (float) ($points[$id] ?? 0),
                'profit' => $made,
                'salary' => isset($salary[$id]) ? (float) $salary[$id] : null,
                'bonus' => isset($bonus[$id]) ? (float) $bonus[$id]->amount : null,
                'bonus_note' => $bonus[$id]->note ?? null,
                'pay' => $pay,
                'per_taka' => $pay > 0 ? round($made / $pay, 2) : null,
            ];
        })->values();

        // The team's middle value for each number, then a colour for each person.
        $team = [];
        foreach (array_keys(self::METRICS) as $m) {
            $team[$m] = $this->median($rows->pluck($m)->filter(fn ($v) => $v !== null));
        }
        $rows = $rows->map(function ($r) use ($team) {
            $r['lights'] = [];
            foreach (self::METRICS as $m => $higherBetter) {
                $r['lights'][$m] = $this->light($r[$m], $team[$m], $higherBetter);
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
