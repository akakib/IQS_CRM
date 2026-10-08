<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\Orders\OrderExport;
use App\Services\Orders\OrderStages;
use App\Support\Xlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Orders to Excel or to a printable page (admins and managers: orders.export).
 * Filters: the dates the orders came in, stages, who holds them. Every
 * export is written to the activity log: it carries customer details.
 */
class OrderExportController extends Controller
{
    public function __construct(private OrderExport $export) {}

    /** One pickup's parcels, any time later: the same columns as the orders export (Handover list and manifest). */
    public function handoverExcel(Request $request, int $session): BinaryFileResponse
    {
        $s = DB::table('handover_sessions')->find($session) ?? abort(404);
        $f = $this->handoverFilters($s);
        $rows = $this->export->rows($f, $request->user());
        $this->log($request, 'excel', $f, $rows->count());

        return response()->download($this->xlsx($rows), 'handover_'.$s->pickup_date.'_'.$s->id.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function handoverPrint(Request $request, int $session): View
    {
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
        $s = DB::table('handover_sessions')->find($session) ?? abort(404);
        $f = $this->handoverFilters($s);
        $rows = $this->export->rows($f, $request->user());
        $this->log($request, 'print', $f, $rows->count());

        return view('orders.export.print', [
            'rows' => $rows,
            'totals' => $this->export->totals($f, $request->user()),
            'filters' => $f,
            'title' => __('Handover · :d', ['d' => \Illuminate\Support\Carbon::parse($s->started_at)->format('d M Y, g:i A')]),
            'subtitle' => trim(__('Rider: :r', ['r' => $s->rider_name ?: '-']).' '.($s->rider_phone ?? '')).' · '.ucfirst($s->courier)
                .' · '.($s->closed_at ? __('closed :t', ['t' => \Illuminate\Support\Carbon::parse($s->closed_at)->format('g:i A')]) : __('still open')),
            'stageNames' => null, 'sortName' => __('Scan order'), 'staffName' => null,
            'max' => OrderExport::MAX_ROWS,
        ]);
    }

    private function handoverFilters(object $s): array
    {
        return ['from' => $s->pickup_date, 'to' => $s->pickup_date, 'stages' => [], 'staff' => null, 'sort' => 'placed', 'handover' => (int) $s->id];
    }

    private function xlsx($rows): string
    {
        return Xlsx::write(__('Orders'), [
            __('Order'), __('Placed'), __('Channel'), __('Customer'), __('Phone'), __('Address'), __('Items'),
            __('Total'), __('COD'), __('Status'), __('Moderator'), __('CN'),
        ], $rows->map(fn ($r) => array_values(\Illuminate\Support\Arr::except($r, ['placed_at']))), [10, 20, 10, 20, 14, 40, 50, 10, 10, 16, 14, 12]);
    }

    /** Live count for the panel, before anything is downloaded. */
    public function count(Request $request): JsonResponse
    {
        return response()->json($this->export->totals($this->filters($request), $request->user()));
    }

    public function excel(Request $request): BinaryFileResponse
    {
        $f = $this->filters($request);
        $rows = $this->export->rows($f, $request->user());
        $this->log($request, 'excel', $f, $rows->count());

        return response()->download($this->xlsx($rows), $this->fileName($f), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function print(Request $request): View
    {
        if (app()->bound('debugbar')) {
            app('debugbar')->disable(); // a page made for paper: no debug toolbar on it
        }
        $f = $this->filters($request);
        $rows = $this->export->rows($f, $request->user());
        $this->log($request, 'print', $f, $rows->count());

        return view('orders.export.print', [
            'rows' => $rows,
            'totals' => $this->export->totals($f, $request->user()),
            'filters' => $f,
            'stageNames' => $this->export->stageNames($f['stages']),
            'sortName' => OrderExport::sorts()[$f['sort']],
            'staffName' => $this->staffName($f['staff']),
            'max' => OrderExport::MAX_ROWS,
        ]);
    }

    /** @return array{from: string, to: string, stages: list<string>, staff: ?string, sort: string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'stages' => ['nullable', 'array'], 'stages.*' => ['string', Rule::in(array_keys(OrderStages::all()))],
            'staff' => ['nullable', 'regex:/^(none|\d+)$/'],
            'sort' => ['nullable', Rule::in(array_keys(OrderExport::sorts()))],
        ]);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 92) {
            throw ValidationException::withMessages(['to' => __('Pick at most 3 months at a time.')]);
        }

        return ['from' => $data['from'], 'to' => $data['to'], 'stages' => array_values(array_unique($data['stages'] ?? [])), 'staff' => $data['staff'] ?? null, 'sort' => $data['sort'] ?? 'placed'];
    }

    private function staffName(?string $staff): string
    {
        return match (true) {
            $staff === null => __('Everyone'),
            $staff === 'none' => __('Nobody took them'),
            default => (string) DB::table('users')->where('id', (int) $staff)->value('name'),
        };
    }

    private function fileName(array $f): string
    {
        $stage = count($f['stages']) === 1 ? '_'.$f['stages'][0] : '';

        return 'orders_'.$f['from'].'_to_'.$f['to'].$stage.'.xlsx';
    }

    private function log(Request $request, string $as, array $f, int $rows): void
    {
        app(ActivityLogger::class)->log('orders.exported', null, null, ['as' => $as, 'rows' => $rows] + $f);
    }
}
