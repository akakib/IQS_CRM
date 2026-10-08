<?php

namespace App\Http\Controllers;

use App\Services\Reports\WorkTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Work time: one day per person, measured from the orders' own history (no countdown). */
class WorkTimeController extends Controller
{
    public function index(Request $request, WorkTime $report): View
    {
        $data = $request->validate(['date' => ['nullable', 'date', 'before_or_equal:today'], 'person' => ['nullable', 'integer']]);
        $day = isset($data['date']) ? Carbon::parse($data['date']) : today();
        $result = $report->day($day);
        $person = isset($data['person']) ? $result['people']->firstWhere('id', (int) $data['person']) : null;

        return view('reports.work-time', [
            'day' => $day,
            'people' => $result['people'],
            'person' => $person,
            'turns' => $person ? $result['turns']->get($person['id'], collect()) : collect(),
            'activity' => $person ? app(\App\Services\Reports\PersonActivity::class)->day($person['id'], $day) : null,
        ]);
    }
}
