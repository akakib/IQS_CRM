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

        $closed = $scorecard->closed($month);

        return view('reports.scorecard', ($closed ?? $scorecard->month($month)) + [
            'month' => $month, 'closed' => (bool) $closed, 'closedAt' => $closed['closedAt'] ?? null, 'closedBy' => $closed['closedBy'] ?? null,
            // A finished month can be closed (saved as it stands) by those who decide pay.
            'canClose' => $user->canSeeField('salary') && $user->can('staff.edit') && $month->lt(today()->startOfMonth()),
            'trial' => (bool) settings('points.trial_mode'),
            // Pay and profit only for roles that may see them; bonuses are set by those who edit staff.
            'seePay' => $user->canSeeField('salary') && $user->canSeeField('profit'),
            'canSetBonus' => $user->canSeeField('salary') && $user->can('staff.edit'),
        ]);
    }

    /** Close a finished month: its numbers are saved and stop changing. Reopen (owner) to work it again. */
    public function close(Request $request, MonthlyScorecard $scorecard): RedirectResponse
    {
        abort_unless($request->user()->canSeeField('salary') && $request->user()->can('staff.edit'), 403);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'reopen' => ['nullable', 'boolean']]);
        $month = $this->month($data['month']);
        abort_unless($month->lt(today()->startOfMonth()), 422, __('Only a finished month can be closed.'));
        if ($request->boolean('reopen')) {
            abort_unless($request->user()->isOwner(), 403);
            DB::table('scorecard_closes')->where('month', $month->toDateString())->delete();
            app(\App\Services\ActivityLogger::class)->log('scorecard.reopened', null, null, ['month' => $month->toDateString()]);

            return back()->with('success', __(':m reopened: numbers are live again.', ['m' => $month->format('F Y')]));
        }
        $scorecard->close($month, $request->user()->id);
        app(\App\Services\ActivityLogger::class)->log('scorecard.closed', null, null, ['month' => $month->toDateString()]);

        return back()->with('success', __(':m closed: these numbers no longer change.', ['m' => $month->format('F Y')]));
    }

    public function bonus(Request $request, int $user): RedirectResponse
    {
        abort_unless($request->user()->canSeeField('salary'), 403);
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'amount' => ['nullable', 'numeric', 'min:0', 'max:10000000'], 'note' => ['nullable', 'string', 'max:255']]);
        $month = $this->month($data['month'])->toDateString();
        if (DB::table('scorecard_closes')->where('month', $month)->exists()) {
            return back()->with('error', __('This month is closed. Reopen it to change a bonus.'));
        }
        abort_unless(DB::table('users')->where('id', $user)->exists(), 404);
        abort_if($user === $request->user()->id && ! $request->user()->isOwner(), 403); // not your own bonus
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
