<?php

namespace App\Http\Controllers;

use App\Services\Reports\UpsellReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Upsell: who sold more on website orders, how much, and whether it was delivered (UpsellReport). Staff without Analysis see their own. */
class UpsellReportController extends Controller
{
    public function __invoke(Request $request, UpsellReport $report): View
    {
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $from = $valid($request->query('from')) ? $request->query('from') : today()->startOfMonth()->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : today()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $own = ! $request->user()->can('analysis.view');
        $rows = $report->rows($from, $to, $own ? $request->user()->id : null);

        return view('reports.upsell', ['from' => $from, 'to' => $to, 'rows' => $rows, 'people' => $report->people($rows), 'own' => $own]);
    }
}
