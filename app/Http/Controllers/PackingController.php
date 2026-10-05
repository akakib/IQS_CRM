<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\StatusReason;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Packing\BatchService;
use App\Services\Packing\ScanService;
use App\Services\Packing\StockIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Packer portal: one shared queue for today's on-duty packers. Scanning a
 * label makes the order yours; tick every item, then Packed. Packers see
 * items and shelves only: no phone, no full address, no prices.
 *
 * Page load: shift (1), counts (1), queue (1), my day (2), new labels (1), hold reasons (1).
 */
class PackingController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): View
    {
        $user = $request->user();
        $today = today()->toDateString();
        $onDuty = DB::table('packer_shifts as s')->join('users as u', 'u.id', '=', 's.user_id')->where('s.work_date', $today)->orderBy('u.name')->pluck('u.name', 'u.id');
        $canManage = $user->can('packing.manage');
        // No list set today = everyone with packing access works (so a forgotten list never stops the shop).
        $working = $onDuty->isEmpty() || $onDuty->has($user->id) || $canManage;

        $rfp = OrderStatus::idFor('ready_for_packaging');
        $packed = implode(',', OrderStatus::idsFor(['packed', 'ready_for_pickup']));
        $red = "(o.packed_version IS NOT NULL AND o.packed_version < o.current_version AND o.status_id IN ({$packed}))";
        $orange = "(o.label_version < o.current_version AND o.status_id IN ({$rfp}, {$packed}))";
        $inQueue = "(o.status_id = {$rfp} OR {$red} OR {$orange})";

        $counts = (array) DB::table('orders as o')->whereRaw($inQueue)->selectRaw(
            "COUNT(*) as `all`, SUM(CASE WHEN {$red} THEN 1 ELSE 0 END) as red,
             SUM(CASE WHEN o.status_id = {$rfp} AND o.packer_id IS NULL THEN 1 ELSE 0 END) as waiting,
             SUM(CASE WHEN o.packer_id = ? THEN 1 ELSE 0 END) as mine", [$user->id])->first();
        $counts = array_map('intval', $counts);

        $filter = in_array($request->query('show'), ['red', 'waiting', 'mine'], true) ? $request->query('show') : 'all';
        $queue = DB::table('orders as o')
            ->leftJoin('users as m', 'm.id', '=', 'o.moderator_id')->leftJoin('users as p', 'p.id', '=', 'o.packer_id')
            ->whereRaw($inQueue)
            ->when($filter === 'red', fn ($q) => $q->whereRaw($red))
            ->when($filter === 'waiting', fn ($q) => $q->where('o.status_id', $rfp)->whereNull('o.packer_id'))
            ->when($filter === 'mine', fn ($q) => $q->where('o.packer_id', $user->id))
            ->orderByRaw("{$red} DESC, {$orange} DESC")->orderBy('o.packing_sent_at')->orderBy('o.id')
            ->selectRaw("o.id, o.order_no, o.status_id, o.packing_sent_at, o.packing_started_at, o.packer_id, o.ship_district, o.ship_thana, o.edited_after_pack,
                m.name as moderator, p.name as packer, {$red} as is_red, {$orange} as is_orange,
                (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) as items")
            ->paginate(self::PER_PAGE)->withQueryString();

        $since = now()->startOfDay();
        $mine = DB::table('orders')->where('packer_id', $user->id)->where('packed_at', '>=', $since)->get(['packing_started_at', 'packed_at']);

        return view('packing.index', [
            'working' => $working,
            'onDuty' => $onDuty,
            'canManage' => $canManage,
            'staff' => $canManage ? User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
            'counts' => $counts,
            'filter' => $filter,
            'queue' => $queue,
            'myDay' => [
                'packed' => $mine->count(),
                'avg' => $mine->count() ? (int) round($mine->avg(fn ($o) => $o->packing_started_at ? max(0, strtotime($o->packed_at) - strtotime($o->packing_started_at)) : 0) / 60) : null,
                'errors' => DB::table('scan_logs')->where('user_id', $user->id)->where('created_at', '>=', $since)->whereIn('result', ['blocked', 'unknown'])->count(),
            ],
            'newLabels' => DB::table('shipment_labels as l')->join('orders as o', 'o.id', '=', 'l.order_id')
                ->whereNull('l.voided_at')->whereNull('l.printed_at')->whereRaw($inQueue)->count(),
            'holdReasons' => StatusReason::options('hold'),
            'openIssues' => DB::table('stock_issue_reports')->where('status', 'open')->count(),
        ]);
    }

    /** Today's on-duty packers (replaces the list). */
    public function shift(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate(['user_ids' => ['array', 'max:50'], 'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)]]);
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'] ?? [])));
        $today = today()->toDateString();

        DB::transaction(function () use ($ids, $today, $request) {
            DB::table('packer_shifts')->where('work_date', $today)->whereNotIn('user_id', $ids ?: [0])->delete();
            foreach ($ids as $id) {
                DB::table('packer_shifts')->insertOrIgnore(['work_date' => $today, 'user_id' => $id, 'set_by' => $request->user()->id, 'created_at' => now()]);
            }
        });
        $logger->log('packer_shift.set', ['packer_shift', 0], null, ['date' => $today, 'user_ids' => $ids]);

        return back()->with('success', __('On-duty packers saved for today.'));
    }

    /** Scan = this order is mine; returns the checklist. */
    public function scan(Request $request, ScanService $scans): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);

        return response()->json($scans->open($data['code'], $request->user()));
    }

    /**
     * Tap a card in the queue: what is in the order (items and shelves) and
     * which label to scan. Looking does not claim it; scanning the label does.
     */
    public function preview(Order $order, ScanService $scans): JsonResponse
    {
        $label = DB::table('shipment_labels')->where('order_id', $order->id)->whereNull('voided_at')->first(['barcode', 'printed_at', 'order_version']);
        $repack = $order->packMark() === 'repack';

        return response()->json($scans->checklist($order, $repack) + [
            'label' => $label->barcode ?? null,
            'label_printed' => (bool) ($label->printed_at ?? false),
            'label_current' => $label && (int) $label->order_version === (int) $order->current_version,
        ]);
    }

    /** Every item ticked: Packed. */
    public function pack(Order $order, Request $request, ScanService $scans): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'max:200'], 'items.*' => ['integer']]);

        return response()->json(['ok' => true, 'message' => $scans->finish($order, $data['items'], $request->user())]);
    }

    /** The packer cannot finish it: Hold with a reason. */
    public function hold(Order $order, Request $request, ScanService $scans): RedirectResponse
    {
        $data = $request->validate([
            'reason_id' => ['required', Rule::exists('status_reasons', 'id')->where('reason_type', 'hold')],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $scans->hold($order, (int) $data['reason_id'], $data['note'] ?? null, $request->user());

        return redirect()->route('packing.index')->with('success', __(':no is on hold. The moderator and admin were told.', ['no' => $order->order_no]));
    }

    /** Print every label in the queue that has not been printed yet. */
    public function labels(Request $request): View|RedirectResponse
    {
        $rfp = OrderStatus::idFor('ready_for_packaging');
        $ids = DB::table('shipment_labels as l')->join('orders as o', 'o.id', '=', 'l.order_id')
            ->whereNull('l.voided_at')->whereNull('l.printed_at')
            ->whereIn('o.status_id', [$rfp, ...OrderStatus::idsFor(['packed', 'ready_for_pickup'])])
            ->orderBy('o.packing_sent_at')->limit(100)->pluck('o.id');
        if ($ids->isEmpty()) {
            return redirect()->route('packing.index')->with('error', __('No new label to print.'));
        }

        $orders = Order::whereIn('id', $ids)->with('items:id,order_id,name_snapshot,qty,unit')->get();
        $labels = DB::table('shipment_labels as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')
            ->whereIn('l.order_id', $ids)->whereNull('l.voided_at')
            ->get(['l.order_id', 'l.barcode', 'l.cod_on_label', 's.consignment_id', 's.courier'])->keyBy('order_id');
        DB::table('shipment_labels')->whereIn('order_id', $ids)->whereNull('voided_at')->whereNull('printed_at')
            ->update(['printed_at' => now(), 'printed_by' => $request->user()->id]);

        return view('shipping.labels', ['orders' => $orders, 'labels' => $labels]);
    }

    // ── Batches (older flow, kept for bulk pick lists; not in the menu) ──

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
