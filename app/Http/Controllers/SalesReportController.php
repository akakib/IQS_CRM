<?php

namespace App\Http\Controllers;

use App\Services\Reports\SalesReport;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sales report: how many orders were confirmed, packed and delivered in the dates (side by side),
 * the money and how many kg / litres went out; one step's items below, by day or by month.
 * Print / PDF and Excel give the chosen step's items.
 */
class SalesReportController extends Controller
{
    public function __construct(private SalesReport $sales) {}

    public function index(Request $request): View
    {
        [$from, $to, $stage, $by] = $this->filters($request);

        return view('reports.sales', [
            'from' => $from, 'to' => $to, 'stage' => $stage, 'by' => $by,
            'summary' => $this->sales->summary($from, $to),
            'products' => $this->sales->products($stage, $from, $to),
            'periods' => $this->sales->periods($stage, $from, $to, $by),
        ]);
    }

    public function print(Request $request): View
    {
        [$from, $to, $stage] = $this->filters($request);

        return view('reports.sales-print', [
            'from' => $from, 'to' => $to, 'stage' => $stage,
            'summary' => $this->sales->summary($from, $to)[$stage],
            'products' => $this->sales->products($stage, $from, $to),
        ]);
    }

    public function excel(Request $request): BinaryFileResponse
    {
        [$from, $to, $stage] = $this->filters($request);
        $rows = $this->sales->products($stage, $from, $to)->map(fn ($p) => [
            $p->product.($p->variant !== 'Default' ? ' · '.$p->variant : ''), $p->sku, (float) $p->qty,
            $p->grams > 0 ? round($p->grams / 1000, 3) : null, $p->ml > 0 ? round($p->ml / 1000, 3) : null, (float) $p->money,
        ]);
        $file = \App\Support\Xlsx::write(__(SalesReport::STAGES[$stage]), [__('Item'), 'SKU', __('Qty'), __('Kg'), __('Litre'), __('Amount')], $rows, [48, 16, 8, 10, 10, 12]);

        return response()->download($file, __('Sales :s :f to :t', ['s' => __(SalesReport::STAGES[$stage]), 'f' => $from, 't' => $to]).'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} from, to, step, day or month */
    private function filters(Request $request): array
    {
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $from = $valid($request->query('from')) ? $request->query('from') : today()->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : today()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $stage = array_key_exists((string) $request->query('stage'), SalesReport::STAGES) ? $request->query('stage') : 'confirmed';
        $by = $request->query('by') === 'month' ? 'month' : 'day';

        return [$from, $to, $stage, $by];
    }
}
