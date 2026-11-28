<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\StatusReason;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Packaging\BatchService;
use App\Services\Packaging\LabelPrinter;
use App\Services\Packaging\PackagingPause;
use App\Services\Packaging\ScanService;
use App\Services\Packaging\StockIssueService;
use App\Support\Lists\ListState;
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
class PackagingController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * Orders already packed (Today's packed / Packed by date): who packed, when, the items with their photos,
     * and whether the rider has it. The customer's phone only for people who may see orders (never the shop).
     * Counts: 2 queries; the list (Today's packed / Packed): 1 page query + 1 for the items.
     *
     * @return array{today: int, total: int, rows: ?\Illuminate\Contracts\Pagination\LengthAwarePaginator, items: \Illuminate\Support\Collection, list: ?ListState, phone: bool, packers: array}
     */
    private function packedList(Request $request, User $user, bool $canManage, string $filter): array
    {
        $own = fn ($q) => $q->whereNotNull('o.packed_at')->when(! $canManage, fn ($w) => $w->where('o.packer_id', $user->id));
        $out = [
            'today' => DB::table('orders as o')->tap($own)->where('o.packed_at', '>=', today())->count(),
            'total' => DB::table('orders as o')->tap($own)->count(),
            'rows' => null, 'items' => collect(), 'list' => null, 'phone' => $user->can('orders.view'), 'packers' => [],
        ];
        if (! in_array($filter, ['today', 'packed'], true)) {
            return $out;
        }
        $list = ListState::from($request, ['packed_at'], ['from' => 'date', 'to' => 'date', 'packer' => 'int', 'rider' => ['waiting', 'handed']], 'desc');
        $q = $list->search;
        $digits = preg_replace('/\D/', '', $q);
        $handover = 'SELECT l.created_at FROM scan_logs l WHERE l.order_id = o.id AND l.station = \'handover\' AND l.result = \'ok\' ORDER BY l.id DESC LIMIT 1';
        $rider = 'SELECT s.rider_name FROM scan_logs l JOIN handover_sessions s ON s.id = l.handover_session_id WHERE l.order_id = o.id AND l.station = \'handover\' AND l.result = \'ok\' ORDER BY l.id DESC LIMIT 1';
        $out['rows'] = DB::table('orders as o')->tap($own)
            ->leftJoin('users as p', 'p.id', '=', 'o.packer_id')->leftJoin('shipments as sh', 'sh.id', '=', 'o.active_shipment_id')
            ->when($filter === 'today', fn ($w) => $w->where('o.packed_at', '>=', today()))
            ->when($filter === 'packed' && $list->filter('from'), fn ($w, $d) => $w->where('o.packed_at', '>=', $list->filter('from').' 00:00:00'))
            ->when($filter === 'packed' && $list->filter('to'), fn ($w) => $w->where('o.packed_at', '<=', $list->filter('to').' 23:59:59'))
            ->when($canManage && $list->filter('packer'), fn ($w, $id) => $w->where('o.packer_id', $list->filter('packer')))
            ->when($list->filter('rider') === 'waiting', fn ($w) => $w->whereRaw("NOT EXISTS ({$handover})"))
            ->when($list->filter('rider') === 'handed', fn ($w) => $w->whereRaw("EXISTS ({$handover})"))
            // Order no, CN or name; a phone only for people who may see it (the shop goes by the order no).
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('o.order_no', strtoupper($q))->orWhere('sh.consignment_id', $q)->orWhere('o.ship_name', 'like', '%'.$q.'%')
                ->when($out['phone'] && strlen($digits) >= 6, fn ($x) => $x->orWhere('o.ship_phone', 'like', '%'.$digits.'%'))))
            ->orderByDesc('o.packed_at')->orderByDesc('o.id')
            ->selectRaw("o.id, o.order_no, o.status_id, o.ship_name, o.ship_district, o.ship_thana, o.packed_at, o.packer_id, p.name as packer, sh.consignment_id,
                ".($out['phone'] ? 'o.ship_phone' : 'NULL as ship_phone').", ({$handover}) as handed_at, ({$rider}) as rider")
            ->paginate(self::PER_PAGE)->withQueryString();
        $ids = $out['rows']->pluck('id');
        $out['items'] = $ids->isEmpty() ? collect() : DB::table('order_items as i')->leftJoin('product_variants as v', 'v.id', '=', 'i.variant_id')->leftJoin('products as pr', 'pr.id', '=', 'v.product_id')
            ->whereIn('i.order_id', $ids)->orderBy('i.id')->get(['i.order_id', 'i.name_snapshot', 'i.qty', 'i.unit', 'pr.image_url'])->groupBy('order_id');
        $out['list'] = $list;
        $out['packers'] = $canManage ? User::whereIn('id', DB::table('orders')->whereNotNull('packed_at')->where('packed_at', '>=', now()->subDays(60))->distinct()->pluck('packer_id')->filter())
            ->orderBy('name')->pluck('name', 'id')->all() : [];

        return $out;
    }

    /**
     * The Today sheet: today's batches (and orders packed today outside a batch), each order with its label, items
     * and the two ticks. Queries: batches 1, orders 1, items 1, labels 1, open undo requests 1.
     *
     * @return array{groups: \Illuminate\Support\Collection, items: \Illuminate\Support\Collection, labels: \Illuminate\Support\Collection, asks: \Illuminate\Support\Collection, totals: array}
     */
    private function todaySheet(): array
    {
        $batches = DB::table('batches as b')->leftJoin('users as u', 'u.id', '=', 'b.released_by')->where('b.created_at', '>=', today())
            ->orderByDesc('b.id')->get(['b.id', 'b.batch_no', 'b.released_at', 'u.name as by']);
        $orders = DB::table('orders as o')->leftJoin('users as p', 'p.id', '=', 'o.packer_id')
            ->where(fn ($w) => $w->whereIn('o.batch_id', $batches->pluck('id'))->orWhere(fn ($x) => $x->whereNull('o.batch_id')->where('o.packed_at', '>=', today())))
            ->orderBy('o.packaging_sent_at')->orderBy('o.id')
            ->get(['o.id', 'o.order_no', 'o.batch_id', 'o.status_id', 'o.ship_district', 'o.ship_thana', 'o.cod_amount', 'o.packed_at', 'o.packed_version', 'o.current_version', 'o.label_version', 'p.name as packer']);
        $ids = $orders->pluck('id');
        $items = $ids->isEmpty() ? collect() : DB::table('order_items as i')->leftJoin('product_variants as v', 'v.id', '=', 'i.variant_id')->leftJoin('products as pr', 'pr.id', '=', 'v.product_id')
            ->whereIn('i.order_id', $ids)->orderBy('i.id')->get(['i.order_id', 'i.name_snapshot', 'i.qty', 'i.unit', 'v.size_qty', 'v.size_unit', 'pr.image_url'])->groupBy('order_id');
        $labels = $ids->isEmpty() ? collect() : DB::table('shipment_labels as l')->leftJoin('users as u', 'u.id', '=', 'l.applied_by')->whereIn('l.order_id', $ids)->whereNull('l.voided_at')
            ->orderBy('l.id')->get(['l.order_id', 'l.barcode', 'l.applied_at', 'u.name as by'])->keyBy('order_id');
        $asks = $ids->isEmpty() ? collect() : DB::table('packing_undo_requests as r')->leftJoin('users as u', 'u.id', '=', 'r.requested_by')->whereIn('r.order_id', $ids)->where('r.status', 'open')
            ->get(['r.id', 'r.order_id', 'r.mark', 'r.reason', 'r.created_at', 'u.name as by'])->groupBy('order_id');
        $repack = fn ($o) => $o->packed_version !== null && (int) $o->packed_version < (int) $o->current_version;
        $groups = $batches->map(fn ($b) => ['batch' => $b, 'orders' => $orders->where('batch_id', $b->id)->values()])
            ->push(['batch' => null, 'orders' => $orders->whereNull('batch_id')->values()])->filter(fn ($g) => $g['orders']->isNotEmpty())->values();

        return ['groups' => $groups, 'items' => $items, 'labels' => $labels, 'asks' => $asks, 'repack' => $repack, 'totals' => [
            'orders' => $orders->count(),
            'packed' => $orders->filter(fn ($o) => $o->packed_at && ! $repack($o))->count(),
            'label' => $orders->filter(fn ($o) => $labels->get($o->id)?->applied_at)->count(),
        ]];
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $today = today()->toDateString();
        $onDuty = DB::table('packer_shifts as s')->join('users as u', 'u.id', '=', 's.user_id')->where('s.work_date', $today)->orderBy('u.name')->pluck('u.name', 'u.id');
        $canManage = $user->can('packaging.manage');
        app(\App\Services\Orders\DeskService::class)->bookDue();
        // No list set today = everyone with packaging access works (so a forgotten list never stops the shop).
        $working = $onDuty->isEmpty() || $onDuty->has($user->id) || $canManage;

        // The list and its counts (the dashboard's To pack tile reads the same): paused orders stay out for the packers.
        $queue = app(\App\Services\Packaging\PackingQueue::class);
        ['sql' => $inQueue, 'red' => $red, 'orange' => $orange, 'rfp' => $rfp] = $queue->sql($user);
        $counts = $queue->counts($user);
        $pause = app(\App\Services\Packaging\PackagingPause::class);
        $controls = $pause->canControl($user);

        // The packer's page: Ongoing = printed or started (in a batch, a label printed, a packer on it, changed after packing);
        // To pack = the rest, waiting for Print next batch. Opening the page: Ongoing first while it has work.
        $simple = $this->isSimple($user);
        $ongoing = "(o.batch_id IS NOT NULL OR o.packer_id IS NOT NULL OR {$red} OR {$orange} OR EXISTS (SELECT 1 FROM shipment_labels sl WHERE sl.order_id = o.id AND sl.voided_at IS NULL AND sl.printed_at IS NOT NULL))";
        $onGoingCount = $simple ? DB::table('orders as o')->whereRaw($inQueue)->whereRaw($ongoing)->count() : 0;
        $filter = in_array($request->query('show'), ['red', 'waiting', 'mine', 'hold', 'today', 'packed', 'ongoing'], true) ? $request->query('show')
            : ($simple && $onGoingCount > 0 && ! $request->has('show') ? 'ongoing' : 'all');
        // Packed already: today's, or any day by date. A packer sees their own; a manager everyone's (by packer).
        $packed = $this->packedList($request, $user, $canManage, $filter);
        $counts['today'] = $packed['today'];
        $counts['packed'] = $packed['total'];
        // Reached the packers, then put on hold: the parcel is set aside (its label stays with it) until it goes on.
        $heldQuery = DB::table('orders as o')->where('o.status_id', OrderStatus::idFor('hold'))->whereNotNull('o.packaging_sent_at')->whereNotNull('o.active_shipment_id');
        $counts['hold'] = (clone $heldQuery)->count();
        $held = $filter !== 'hold' ? collect() : (clone $heldQuery)->leftJoin('status_reasons as r', 'r.id', '=', 'o.hold_reason_id')->leftJoin('users as m', 'm.id', '=', 'o.moderator_id')
            ->orderByDesc('o.updated_at')->limit(100)
            ->get(['o.id', 'o.order_no', 'o.ship_district', 'o.hold_note', 'o.hold_expected_date', 'o.updated_at', 'r.label_en as reason', 'm.name as moderator',
                DB::raw('(SELECT barcode FROM shipment_labels l WHERE l.order_id = o.id AND l.voided_at IS NULL ORDER BY l.id DESC LIMIT 1) as label')]);
        $queue = DB::table('orders as o')
            ->leftJoin('users as m', 'm.id', '=', 'o.moderator_id')->leftJoin('users as p', 'p.id', '=', 'o.packer_id')
            ->whereRaw($inQueue)->when(in_array($filter, ['today', 'packed'], true), fn ($q) => $q->whereRaw('0 = 1'))
            ->when($filter === 'red', fn ($q) => $q->whereRaw($red))
            ->when($filter === 'waiting', fn ($q) => $q->where('o.status_id', $rfp)->whereNull('o.packer_id'))
            ->when($filter === 'mine', fn ($q) => $q->where('o.packer_id', $user->id))
            ->when($filter === 'ongoing', fn ($q) => $q->whereRaw($ongoing))
            ->when($simple && $filter === 'all', fn ($q) => $q->whereRaw("NOT {$ongoing}"))
            ->orderByRaw("{$red} DESC, {$orange} DESC")->orderBy('o.packaging_sent_at')->orderBy('o.id')
            ->selectRaw("o.id, o.order_no, o.status_id, o.packaging_sent_at, o.packaging_started_at, o.packer_id, o.ship_district, o.ship_thana, o.edited_after_pack,
                m.name as moderator, p.name as packer, {$red} as is_red, {$orange} as is_orange,
                (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) as items")
            ->paginate(self::PER_PAGE)->withQueryString();

        $since = now()->startOfDay();
        $mine = DB::table('orders')->where('packer_id', $user->id)->where('packed_at', '>=', $since)->get(['packaging_started_at', 'packed_at']);

        // A packer (no orders, no packaging management): the simple page, two tabs, one button per order.
        if ($simple && in_array($filter, ['all', 'ongoing', 'packed'], true)) {
            $ids = $queue->pluck('id');
            $counts['ongoing'] = $onGoingCount;
            $counts['to_pack'] = max(0, $counts['all'] - $onGoingCount);
            return view('packaging.simple', [
                'filter' => $filter, 'queue' => $queue, 'packed' => $packed, 'counts' => $counts,
                'nextBatch' => $user->can('packaging.create') ? app(BatchService::class)->nextUp($user) : null,
                'items' => $ids->isEmpty() ? collect() : DB::table('order_items as i')->leftJoin('product_variants as v', 'v.id', '=', 'i.variant_id')->leftJoin('products as pr', 'pr.id', '=', 'v.product_id')
                    ->whereIn('i.order_id', $ids)->orderBy('i.id')->get(['i.order_id', 'i.name_snapshot', 'i.qty', 'i.unit', 'v.size_qty', 'v.size_unit', 'pr.image_url'])->groupBy('order_id'),
                'labels' => $ids->isEmpty() ? collect() : DB::table('shipment_labels')->whereIn('order_id', $ids)->whereNull('voided_at')->orderBy('id')->get(['order_id', 'barcode', 'printed_at', 'order_version'])->keyBy('order_id'),
                'versions' => $ids->isEmpty() ? collect() : DB::table('orders')->whereIn('id', $ids)->get(['id', 'label_version', 'current_version', 'cod_amount'])->keyBy('id'),
                // A packer's reasons only (not the moderator's: advance, a date, the customer waits).
                'holdReasons' => DB::table('status_reasons')->where('reason_type', 'hold')->where('is_active', true)
                    ->whereIn('system_key', ['item_not_found', 'item_damaged', 'stock_out'])->orderBy('sort_order')->pluck('label_en', 'id')->all() ?: StatusReason::options('hold'),
            ]);
        }

        return view('packaging.index', [
            'working' => $working,
            'onDuty' => $onDuty,
            'helpersToday' => DB::table('packer_shifts')->where('work_date', $today)->pluck('helpers', 'user_id'),
            'canManage' => $canManage,
            // Today's packers: staff only; the owner and managers (who approve orders) do not pack.
            'staff' => $canManage ? User::where('is_active', true)->orderBy('name')->get()->reject(fn ($u) => $u->isOwner() || $u->can('orders.approve'))->pluck('name', 'id')->all() : [],
            'counts' => $counts,
            'filter' => $filter,
            'queue' => $queue,
            'packed' => $packed,
            'sheet' => $filter === 'today' ? $this->todaySheet() : null,
            'canUndo' => $this->canUndo($user),
            'heldInShop' => $held,
            'myDay' => [
                'packed' => $mine->count(),
                'avg' => $mine->count() ? (int) round($mine->avg(fn ($o) => $o->packaging_started_at ? max(0, strtotime($o->packed_at) - strtotime($o->packaging_started_at)) : 0) / 60) : null,
                'errors' => DB::table('scan_logs')->where('user_id', $user->id)->where('created_at', '>=', $since)->whereIn('result', ['blocked', 'unknown'])->count(),
            ],
            // Print by batch: what the next one would take (by lines of paper), and today's batches with how far each got.
            'nextBatch' => $user->can('packaging.create') ? app(BatchService::class)->nextUp($user) : null,
            'batchesToday' => DB::table('batches as b')->leftJoin('users as u', 'u.id', '=', 'b.released_by')->where('b.created_at', '>=', today())
                ->orderByDesc('b.id')->limit(40)
                ->get(['b.id', 'b.batch_no', 'b.released_at', 'u.name as by',
                    DB::raw('(SELECT COUNT(*) FROM orders o WHERE o.batch_id = b.id) as orders'),
                    DB::raw('(SELECT COUNT(*) FROM orders o WHERE o.batch_id = b.id AND o.packed_at IS NOT NULL) as packed')]),
            // A label not printed yet on an order already in a batch: it changed after printing (new orders go by batch).
            'newLabels' => DB::table('shipment_labels as l')->join('orders as o', 'o.id', '=', 'l.order_id')
                ->whereNull('l.voided_at')->whereNull('l.printed_at')->whereNotNull('o.batch_id')->whereRaw($inQueue)->count(),
            'holdReasons' => StatusReason::options('hold'),
            // Boxes to open: taken back or cancelled after a packer started them.
            'toUnpack' => DB::table('orders as o')->leftJoin('users as p', 'p.id', '=', 'o.unpack_packer_id')->whereNotNull('o.unpack_needed_at')
                ->orderBy('o.unpack_needed_at')->limit(50)->get(['o.id', 'o.order_no', 'o.unpack_needed_at', 'o.unpack_packer_id', 'p.name as packer']),
            'openIssues' => DB::table('stock_issue_reports')->where('status', 'open')->count(),
            'pause' => $controls ? $pause->active() : null,
            'held' => $controls ? $pause->heldCount() : 0,
            'controlsPause' => $controls,
        ]);
    }

    /** Pause packaging / Resume (managers): see PackagingPause. */
    public function pause(Request $request, \App\Services\Packaging\PackagingPause $pause, ActivityLogger $logger): RedirectResponse
    {
        abort_unless($pause->canControl($request->user()), 403);
        if ($pause->active()) {
            $n = $pause->resume($request->user());
            $logger->log('packaging.resumed', null, null, ['released' => $n]);

            return back()->with('success', __('Packaging resumed: :n orders sent to the packers.', ['n' => $n]));
        }
        $pause->pause($request->user());
        $logger->log('packaging.paused');

        return back()->with('success', __('Packaging paused. Orders are still booked; packers get them when you resume.'));
    }

    /** The packer opened a taken-back or cancelled box and put the items back. */
    public function unpacked(Order $order, Request $request): RedirectResponse
    {
        abort_unless((int) $order->unpack_packer_id === $request->user()->id || $request->user()->can('packaging.manage') || ! $order->unpack_packer_id, 403);
        app(\App\Services\Orders\DeskService::class)->markUnpacked($order, $request->user());

        return back()->with('success', __(':no: items are back on the shelf.', ['no' => $order->order_no]));
    }

    /** What the packing list holds now, for the page to notice a change (one query for the counts, one for boxes to open). */
    public function pulse(Request $request): JsonResponse
    {
        $counts = app(\App\Services\Packaging\PackingQueue::class)->counts($request->user());

        return response()->json(['sig' => implode('-', [$counts['all'], $counts['red'], $counts['waiting'], $counts['mine'], DB::table('orders')->whereNotNull('unpack_needed_at')->count()])]);
    }

    /** Today's on-duty packers (replaces the list). */
    public function shift(Request $request, ActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'user_ids' => ['array', 'max:50'], 'user_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
            'helpers' => ['array'], 'helpers.*' => ['nullable', 'string', 'max:255'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'] ?? [])));
        $today = today()->toDateString();
        // Helpers: names, comma separated, per packer (no login). The count is what the report uses.
        $helpers = collect($data['helpers'] ?? [])->map(fn ($v) => collect(explode(',', (string) $v))->map(fn ($n) => trim($n))->filter()->unique()->take(20)->values());

        DB::transaction(function () use ($ids, $today, $request, $helpers) {
            DB::table('packer_shifts')->where('work_date', $today)->whereNotIn('user_id', $ids ?: [0])->delete();
            foreach ($ids as $id) {
                $names = $helpers->get($id, collect());
                DB::table('packer_shifts')->updateOrInsert(['work_date' => $today, 'user_id' => $id],
                    ['helpers' => $names->join(', ') ?: null, 'helper_count' => $names->count(), 'set_by' => $request->user()->id, 'created_at' => now()]);
            }
        });
        $logger->log('packer_shift.set', ['packer_shift', 0], null, ['date' => $today, 'user_ids' => $ids, 'helpers' => $helpers->map->all()->all()]);

        return back()->with('success', __('On-duty packers saved for today.'));
    }

    /** Scan = this order is mine; returns the checklist. */
    public function scan(Request $request, ScanService $scans): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:80'], 'via' => ['nullable', 'in:camera,scanner,typed']]);

        return response()->json($scans->open($data['code'], $request->user(), ($data['via'] ?? null) === 'typed'));
    }

    /**
     * Tap a card in the queue: what is in the order (items and shelves) and
     * which label to scan. Looking does not claim it; scanning the label does.
     */
    public function preview(Order $order, Request $request, ScanService $scans, PackagingPause $pause): JsonResponse
    {
        abort_unless(in_array($order->status_id, LabelPrinter::queueStatuses(), true), 404);
        abort_if(! $pause->canControl($request->user()) && $pause->hidden($order), 404);
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

        return back()->with('success', __(':no is on hold. The moderator and admin were told.', ['no' => $order->order_no]));
    }

    /** Print every label in the queue that has not been printed yet. */
    /**
     * Print labels: the orders picked in the list (?orders=1,2,3, one or many, printed before or not), or every
     * label not printed yet. A picked order that changed since its label gets the new label first.
     */
    public function labels(Request $request, LabelPrinter $printer): View|RedirectResponse
    {
        $picked = collect(explode(',', (string) $request->query('orders')))->filter();
        $print = $picked->isNotEmpty() ? $printer->picked($picked, $request->user()) : $printer->fresh($request->user());
        if (! $print) {
            return redirect()->route('packaging.index')->with('error', $picked->isNotEmpty() ? __('These orders have no label to print.') : __('No new label to print.'));
        }

        return view('shipping.labels', $print);
    }

    /**
     * The A4 packing sheet: the orders of one label print (?run=), or, without one, everything now in the
     * packers' list. Same order as the stickers; a label replaced since (the order changed) is flagged.
     */
    public function sheet(Request $request): View
    {
        $run = preg_match('/^\d{10,20}$/', (string) $request->query('run')) ? $request->query('run') : null;
        $pause = app(PackagingPause::class);
        $hide = $pause->canControl($request->user()) ? '' : $pause->visibleSql('o');
        $summary = null;
        if ($request->boolean('today')) {
            // Today's sheet: everything that reached packaging today, plus what still waits from before, oldest first,
            // with today's totals on top. Each order with its items; the ones already packed say so.
            $inShop = OrderStatus::groupIds('packaging');
            $printed = DB::table('shipment_labels as l')->join('orders as o', 'o.id', '=', 'l.order_id')->whereNull('l.voided_at')
                ->where(fn ($w) => $w->where('o.packaging_sent_at', '>=', today())->orWhere('o.status_id', OrderStatus::idFor('ready_for_packaging')))
                ->whereNotIn('o.status_id', OrderStatus::idsFor(['cancelled']))->when($hide !== '', fn ($q) => $q->whereRaw($hide))
                ->orderBy('o.packaging_sent_at')->orderBy('o.id')->limit(500)->get(['l.order_id', 'l.barcode', 'l.voided_at']);
            $today = DB::table('orders as o')->whereIn('o.id', $printed->pluck('order_id'))->selectRaw(
                'COUNT(*) as total, SUM(CASE WHEN o.packed_at IS NOT NULL THEN 1 ELSE 0 END) as packed, SUM(CASE WHEN o.status_id = ? THEN 1 ELSE 0 END) as to_pack,
                 SUM(CASE WHEN o.status_id IN ('.implode(',', array_map('intval', array_slice($inShop, 1))).') THEN 1 ELSE 0 END) as rider',
                [OrderStatus::idFor('ready_for_packaging')])->first();
            $summary = array_map('intval', (array) $today);
        } elseif ($run) {
            $printed = DB::table('shipment_labels as l')->join('orders as o', 'o.id', '=', 'l.order_id')->where('l.print_run', $run)
                ->when($hide !== '', fn ($q) => $q->whereRaw($hide))->orderBy('l.printed_at')->orderBy('l.id')->get(['l.order_id', 'l.barcode', 'l.voided_at']);
        } else {
            $printed = DB::table('shipment_labels as l')->join('orders as o', 'o.id', '=', 'l.order_id')->whereNull('l.voided_at')
                ->where('o.status_id', OrderStatus::idFor('ready_for_packaging'))->when($hide !== '', fn ($q) => $q->whereRaw($hide))
                ->orderBy('o.packaging_sent_at')->orderBy('o.id')->limit(200)->get(['l.order_id', 'l.barcode', 'l.voided_at']);
        }
        $orders = Order::whereIn('id', $printed->pluck('order_id'))->with('items:id,order_id,variant_id,name_snapshot,qty,unit', 'items.variant:id,size_qty,size_unit')->get()->keyBy('id');
        $current = DB::table('shipment_labels')->whereIn('order_id', $printed->pluck('order_id'))->whereNull('voided_at')->pluck('barcode', 'order_id');
        $rows = $printed->filter(fn ($l) => isset($orders[$l->order_id]))->map(fn ($l) => (object) [
            'label' => $l->barcode, 'order' => $orders[$l->order_id], 'changed' => $l->voided_at !== null, 'current' => $current[$l->order_id] ?? null,
        ])->values();

        return view('packaging.sheet', ['rows' => $rows, 'summary' => $summary]);
    }

    /** The Today sheet: Packed or Label on, after the yes in its popup. Locked once given. */
    public function mark(Request $request, Order $order, string $mark, \App\Services\Packaging\PackMarks $marks): RedirectResponse
    {
        $message = $mark === 'packed' ? $marks->packed($order, $request->user()) : $marks->labelOn($order, $request->user());

        return redirect()->to(route('packaging.index', ['show' => 'today']).'#o'.$order->id)->with('success', $message);
    }

    /** A packer asks the owner or a manager to take a tick back. */
    public function undoAsk(Request $request, Order $order, \App\Services\Packaging\PackMarks $marks): RedirectResponse
    {
        $data = $request->validate(['mark' => ['required', Rule::in(['packed', 'label'])], 'reason' => ['required', 'string', 'min:3', 'max:300']]);
        $marks->ask($order, $data['mark'], $data['reason'], $request->user());

        return redirect()->to(route('packaging.index', ['show' => 'today']).'#o'.$order->id)->with('success', __('Asked. The tick stays until the owner or a manager answers.'));
    }

    /** The owner or a manager answers an undo request. */
    public function undoDecide(Request $request, int $undo, \App\Services\Packaging\PackMarks $marks): RedirectResponse
    {
        abort_unless($this->canUndo($request->user()), 403);
        $message = $marks->decide($undo, $request->input('decision') === 'approve', $request->user());

        return redirect()->route('packaging.index', ['show' => 'today'])->with('success', $message);
    }

    /** The owner or a manager takes a tick back themselves, with a reason. */
    public function undoNow(Request $request, Order $order, \App\Services\Packaging\PackMarks $marks): RedirectResponse
    {
        abort_unless($this->canUndo($request->user()), 403);
        $data = $request->validate(['mark' => ['required', Rule::in(['packed', 'label'])], 'reason' => ['required', 'string', 'min:3', 'max:300']]);
        DB::transaction(fn () => $marks->undo($order, $data['mark'], $request->user(), $data['reason']));

        return redirect()->to(route('packaging.index', ['show' => 'today']).'#o'.$order->id)->with('success', __('Taken back on :no.', ['no' => $order->order_no]));
    }

    /** The packer's simple page: packaging work only (Owners and managers keep the full page). */
    private function isSimple(User $user): bool
    {
        return ! $user->isOwner() && ! $user->can('packaging.manage') && ! $user->can('orders.edit') && $user->can('packaging.view');
    }

    /** Done (the simple page): everything in the box and the label on it, one yes. */
    public function markDone(Request $request, Order $order, \App\Services\Packaging\PackMarks $marks): RedirectResponse
    {
        DB::transaction(function () use ($order, $request, $marks) {
            $marks->packed($order, $request->user());
            $marks->labelOn($order->refresh(), $request->user());
        });

        return redirect()->route('packaging.index')->with('success', __(':no done: packed, label on.', ['no' => $order->order_no]));
    }

    private function canUndo(User $user): bool
    {
        return $user->isOwner() || $user->can('packaging.manage');
    }

    /** Print next batch: the oldest orders that fit the batch list (BatchService::nextUp), then its print page. */
    public function nextBatch(Request $request, BatchService $batches): RedirectResponse
    {
        $id = $batches->makeNext($request->user());

        return $id ? redirect()->route('packaging.batch-list', $id) : back()->with('error', __('No new order to print.'));
    }

    /**
     * One batch on the 80 mm receipt printer: what to take from the shelves (by item), then each order with its
     * items to tick. No customer name or phone: the shop goes by the order no. Prints again the same (lost or torn).
     */
    public function batchList(int $batch, BatchService $batches): View
    {
        $b = DB::table('batches as b')->leftJoin('users as u', 'u.id', '=', 'b.released_by')->where('b.id', $batch)->first(['b.*', 'u.name as by']);
        abort_unless($b, 404);
        $orders = Order::where('batch_id', $batch)->orderBy('packaging_sent_at')->orderBy('id')
            ->with('items:id,order_id,variant_id,name_snapshot,qty,unit', 'items.variant:id,shelf_code,size_qty,size_unit')
            ->get(['id', 'order_no', 'ship_thana', 'ship_district', 'active_shipment_id', 'packed_at']);
        $cn = DB::table('shipments')->whereIn('id', $orders->pluck('active_shipment_id')->filter())->pluck('consignment_id', 'id');
        $dayNo = DB::table('batches')->whereDate('created_at', \Illuminate\Support\Carbon::parse($b->created_at)->toDateString())->where('id', '<=', $b->id)->count();

        return view('packaging.batch-list', [
            'batch' => $b, 'orders' => $orders, 'cn' => $cn, 'dayNo' => $dayNo,
            'todayTotal' => DB::table('orders')->whereIn('batch_id', DB::table('batches')->whereDate('created_at', \Illuminate\Support\Carbon::parse($b->created_at)->toDateString())->where('id', '<=', $b->id)->pluck('id'))->count(),
        ]);
    }

    // ── Batches (older flow, kept for bulk pick lists; not in the menu) ──

    public function release(Request $request, BatchService $batches): RedirectResponse
    {
        $id = $batches->release(null, $request->user());

        return $id ? redirect()->route('packaging.batch', $id)->with('success', __('Batch released and sent to the shop.')) : back()->with('error', __('No booked order is waiting.'));
    }

    public function batch(int $batch, BatchService $batches): View
    {
        $row = DB::table('batches')->find($batch);
        abort_unless($row, 404);

        return view('packaging.batch', [
            'batch' => $row,
            'lines' => $batches->pickList($batch),
            'orders' => Order::where('batch_id', $batch)->with('items:id,order_id,variant_id,name_snapshot,qty,unit', 'items.variant:id,product_id,size_qty,size_unit', 'items.variant.product:id,image_url')
                ->get(['id', 'order_no', 'status_id', 'stock_issue_flag', 'packed_version', 'current_version', 'edited_after_pack', 'is_duplicate_flag', 'duplicate_of_no', 'duplicate_items', 'duplicate_address']),
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
        return view('packaging.issues', [
            'reports' => DB::table('stock_issue_reports as r')->join('product_variants as v', 'v.id', '=', 'r.variant_id')
                ->join('products as p', 'p.id', '=', 'v.product_id')->leftJoin('users as u', 'u.id', '=', 'r.reported_by')
                ->leftJoin('orders as o', 'o.id', '=', 'r.order_id')
                ->where('r.status', 'open')->orderBy('r.id')
                ->get(['r.*', 'p.name as product', 'p.image_url', 'v.name as variant', 'v.sku', 'v.shelf_code', 'u.name as reporter', 'o.order_no']),
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
