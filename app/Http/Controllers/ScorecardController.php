<?php

namespace App\Http\Controllers;

use App\Services\Reports\MonthlyScorecard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Monthly scorecard: who is doing well against the team, and what each person costs and brings in. */
class ScorecardController extends Controller
{
    public function index(Request $request, MonthlyScorecard $scorecard): View
    {
        $month = $this->month($request->query('month'));
        $user = $request->user();

        return view('reports.scorecard', $scorecard->month($month) + [
            'month' => $month,
            // Pay and profit only for roles that may see them; bonuses are set by those who edit staff.
            'seePay' => $user->canSeeField('salary') && $user->canSeeField('profit'),
            'canSetBonus' => $user->canSeeField('salary') && $user->can('staff.edit'),
        ]);
    }

    public function bonus(Request $request, int $user): RedirectResponse
    {
        abort_unless($request->user()->canSeeField('salary'), 403);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'], 'note' => ['nullable', 'string', 'max:255']]);
        $month = $this->month($data['month'])->toDateString();
        abort_unless(DB::table('users')->where('id', $user)->exists(), 404);
        if (($data['amount'] ?? null) === null || $data['amount'] === '') {
            DB::table('staff_bonuses')->where('user_id', $user)->where('month', $month)->delete();
        } else {
            DB::table('staff_bonuses')->updateOrInsert(['user_id' => $user, 'month' => $month],
                ['amount' => $data['amount'], 'note' => $data['note'] ?? null, 'set_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);
        }
        app(\App\Services\ActivityLogger::class)->log('staff.bonus_set', null, null, ['user_id' => $user, 'month' => $month, 'amount' => $data['amount'] ?? null]);

        return back()->with('success', __('Bonus saved.'));
    }

    private function month(?string $ym): Carbon
    {
        $m = $ym && preg_match('/^\d{4}-\d{2}$/', $ym) ? Carbon::createFromFormat('Y-m-d', $ym.'-01') : today();

        return $m->startOfMonth()->min(today()->startOfMonth());
    }
}
