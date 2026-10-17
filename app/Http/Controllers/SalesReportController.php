<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Reports\SalesReport;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sales report: how many orders were confirmed, packed and delivered in the dates (side by side, against
 * the same number of days before), where they came from (channel, returning or new customer), by day
 * (by month past two months), the top items and the categories. Every number opens its orders in a popup
 * (orders()). Print / PDF and Excel give the chosen step's items.
 */
class SalesReportController extends Controller
{
    public function __construct(private SalesReport $sales) {}

    public function index(Request $request): View
    {
        [$from, $to, $stage] = $this->filters($request);
        $start = Carbon::parse($from);
        $days = $start->diffInDays(Carbon::parse($to)) + 1;
        $by = $days > 62 ? 'month' : 'day';
        $before = [$start->copy()->subDays($days)->toDateString(), $start->copy()->subDay()->toDateString()];

        // Every day (or month) in the dates, the empty ones too, oldest first: the bars.
        $found = $this->sales->periods($stage, $from, $to, $by)->keyBy('period');
        $bars = collect(CarbonPeriod::create($from, $by === 'month' ? '1 month' : '1 day', $by === 'month' ? Carbon::parse($to)->startOfMonth() : $to))
            ->map(function ($d) use ($found, $by) {
                $key = $d->format($by === 'month' ? 'Y-m' : 'Y-m-d');
                $r = $found[$key] ?? null;

                return ['key' => $key, 'label' => $d->format($by === 'month' ? 'M' : 'j'), 'title' => $d->format($by === 'month' ? 'M Y' : 'd M, D'),
                    'orders' => $r->orders ?? 0, 'money' => $r->money ?? 0, 'kg' => round((($r->grams ?? 0) + ($r->ml ?? 0)) / 1000, 2)];
            })->values();

        return view('reports.sales', [
            'from' => $from, 'to' => $to, 'stage' => $stage, 'by' => $by, 'before' => $before,
            'summary' => $this->sales->summary($from, $to),
            'previous' => $this->sales->summary(...$before),
            'channels' => $this->sales->channels($stage, $from, $to),
            'products' => $this->sales->products($stage, $from, $to),
            'bars' => $bars,
        ]);
    }

    /** A popup's orders: one day (or month), one item or one channel at the chosen step. */
    public function orders(Request $request): View
    {
        [$from, $to, $stage] = $this->filters($request);
        $only = array_filter([
            'day' => preg_match('/^\d{4}-\d{2}(-\d{2})?$/', (string) $request->query('day')) ? $request->query('day') : null,
            'item' => (int) $request->query('item') ?: null,
            'channel' => array_key_exists((string) $request->query('channel'), Order::CHANNELS) ? $request->query('channel') : null,
        ]);
        abort_if($only === [], 404);

        return view('reports._sales-orders', $this->sales->orders($stage, $from, $to, $only) + ['only' => $only, 'canOpen' => $request->user()->can('orders.edit')]);
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

    /** @return array{0: string, 1: string, 2: string} from, to, step */
    private function filters(Request $request): array
    {
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $from = $valid($request->query('from')) ? $request->query('from') : today()->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : today()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $stage = array_key_exists((string) $request->query('stage'), SalesReport::STAGES) ? $request->query('stage') : 'confirmed';

        return [$from, $to, $stage];
    }
}
