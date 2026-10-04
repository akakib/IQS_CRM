<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Services\Packing\BatchService;
use App\Services\Packing\ScanService;
use App\Services\Packing\StockIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Shop floor: release batches, pick lists, scan-to-pack, missing-item reports. */
class PackingController extends Controller
{
    public function index(): View
    {
        $waiting = Order::where('status_id', OrderStatus::idFor('ready_for_packaging'))->whereNull('batch_id')->count();
        $batches = DB::table('batches as b')->leftJoin('users as u', 'u.id', '=', 'b.picked_by')
            ->where('b.created_at', '>=', now()->subDays(3))->orderByDesc('b.id')
            ->get(['b.*', 'u.name as picker']);
        $counts = DB::table('orders')->whereIn('batch_id', $batches->pluck('id'))
            ->selectRaw('batch_id, COUNT(*) as total, SUM(CASE WHEN status_id IN ('.implode(',', OrderStatus::idsFor(['packed', 'ready_for_pickup', 'handed_over', 'in_transit', 'delivered'])).') THEN 1 ELSE 0 END) as packed')
            ->groupBy('batch_id')->get()->keyBy('batch_id');

        return view('packing.index', [
            'waiting' => $waiting,
            'batches' => $batches,
            'counts' => $counts,
            'openIssues' => DB::table('stock_issue_reports')->where('status', 'open')->count(),
        ]);
    }

    public function release(Request $request, BatchService $batches): RedirectResponse
    {
        $id = $batches->release(null, $request->user());

        return $id ? redirect()->route('packing.batch', $id)->with('success', __('Batch released and sent to the shop.')) : back()->with('error', __('No booked order is waiting.'));
    }

    public function batch(int $batch, BatchService $batches): View
    {
        $row = DB::table('batches')->find($batch);
        abort_unless($row, 404);

        return view('packing.batch', [
            'batch' => $row,
            'lines' => $batches->pickList($batch),
            'orders' => Order::where('batch_id', $batch)->with('items:id,order_id,name_snapshot,qty,unit')
                ->get(['id', 'order_no', 'status_id', 'stock_issue_flag', 'packed_version', 'current_version', 'edited_after_pack', 'is_duplicate_flag']),
            'statuses' => OrderStatus::map(),
        ]);
    }

    public function picked(int $batch, Request $request, BatchService $batches): RedirectResponse
    {
        $batches->markPicked($batch, $request->user());

        return back()->with('success', __('Marked as picked.'));
    }

    public function done(int $batch, Request $request, BatchService $batches): RedirectResponse
    {
        $n = $batches->done($batch, $request->user());

        return back()->with('success', trans_choice(':count parcel moved to the pickup shelf.|:count parcels moved to the pickup shelf.', $n, ['count' => $n]));
    }

    public function scanPage(): View
    {
        return view('packing.scan');
    }

    public function scan(Request $request, ScanService $scans): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);

        return response()->json($scans->pack($data['code'], $request->user()));
    }

    public function report(Request $request, StockIssueService $issues): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'order_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $issues->report((int) $data['variant_id'], $data['order_id'] ?? null, $data['batch_id'] ?? null, $request->user(), $data['note'] ?? null);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', __('Reported to Admin.'));
    }

    public function issues(): View
    {
        return view('packing.issues', [
            'reports' => DB::table('stock_issue_reports as r')->join('product_variants as v', 'v.id', '=', 'r.variant_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')->leftJoin('users as u', 'u.id', '=', 'r.reported_by')
                ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
                ->where('r.status', 'open')->orderBy('r.id')
                ->get(['r.*', 'p.name as product', 'v.name as variant', 'v.sku', 'v.shelf_code', 'u.name as reporter', 'o.order_no']),
        ]);
    }

    public function resolve(int $report, Request $request, StockIssueService $issues): RedirectResponse
    {
        abort_unless($request->user()->can('products.availability'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['out_of_stock', 'backorder', 'dismiss'])], 'expected_restock_date' => ['nullable', 'date']]);
        $issues->resolve($report, $data['decision'], $request->user(), $data['expected_restock_date'] ?? null);

        return back()->with('success', __('Decided.'));
    }
}
