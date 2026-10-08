<?php

namespace App\Http\Controllers;

use App\Services\Reports\WorkTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Team Activity: one day per person, measured from the orders' own history (no countdown). */
class WorkTimeController extends Controller
{
    public function index(Request $request, WorkTime $report): View
    {
        $data = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'from' => ['nullable', 'date', 'before_or_equal:today'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'person' => ['nullable', 'integer'],
        ]);
        // One day (date) or a range (from, to); at most 92 days so the page stays quick.
        $from = Carbon::parse($data['from'] ?? $data['date'] ?? today());
        $to = Carbon::parse($data['to'] ?? ($data['from'] ?? null ? today() : $from));
        if ($to->gt(today())) {
            $to = today();
        }
        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92);
        }
        $single = $from->isSameDay($to);
        $result = $report->period($from, $to);
        $person = isset($data['person']) ? $result['people']->firstWhere('id', (int) $data['person']) : null;

        return view('reports.work-time', [
            'day' => $from,
            'from' => $from,
            'to' => $to,
            'single' => $single,
            'people' => $result['people'],
            'person' => $person,
            'turns' => $person ? $result['turns']->get($person['id'], collect()) : collect(),
            'activity' => $person && $single ? app(\App\Services\Reports\PersonActivity::class)->day($person['id'], $from) : null,
        ]);
    }
}
