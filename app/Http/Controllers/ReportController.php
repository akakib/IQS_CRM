<?php

namespace App\Http\Controllers;

use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Reports\KpiScorecard;
use App\Services\Reports\OrderProfit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Dashboard tiles, KPI scorecard and order P&L. */
class ReportController extends Controller
{
    /** About 8 small counted queries, each skipped without its permission. */
    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $today = today()->toDateString();
        // Who sees the whole shop: managers and owners (all orders). Everyone else sees their own numbers,
        // so each tile counts what its link opens. Waiting to be taken is the shared queue for everyone.
        $shop = $user->permissionScope('orders.view') === 'all';
        $todayEvents = fn (string $key) => DB::table('order_events as e')->where('e.to_status_id', OrderStatus::idFor($key))->whereBetween('e.created_at', [$today.' 00:00:00', $today.' 23:59:59']);
        $tiles = [];

        if ($user->can('orders.view')) {
            $tiles[] = $shop
                ? [__('Orders today'), DB::table('orders')->where('created_at', '>=', $today)->count(), route('orders.index'), null]
                : [__('My orders today'), DB::table('orders')->where('created_at', '>=', $today)->where('moderator_id', $user->id)->count(), route('orders.index', ['tab' => 'mine']), null];
            $tiles[] = [__('Waiting to be taken'), app(\App\Services\Orders\DeskService::class)->whereWaiting(DB::table('orders'))->count(), route('desk.index'), null];
            $tiles[] = $shop
                ? [__('Confirmed today'), $todayEvents('confirmed')->count(), null, null]
                : [__('I confirmed today'), $todayEvents('confirmed')->where('e.user_id', $user->id)->count(), null, null];
            // The same issues as the list it opens: all of them for those who see all, else their own.
            $tiles[] = $user->can('orders.approve')
                ? [__('Open delivery issues'), DB::table('delivery_issues')->whereNull('resolved_at')->count(), route('issues.index'), null]
                : [__('My delivery issues'), DB::table('delivery_issues')->whereNull('resolved_at')->where('assigned_to', $user->id)->count(), route('issues.index'), null];
        }
        if ($user->can('packaging.view')) {
            // The packer's day: what is left to pack, what waits for the rider, returns to take in, items not found.
            if (! $shop) {
                $hide = app(\App\Services\Packaging\PackagingPause::class)->visibleSql('orders'); // paused: what came since is not counted
                $tiles[] = [__('To pack'), DB::table('orders')->where('status_id', OrderStatus::idFor('ready_for_packaging'))->when($hide !== '', fn ($q) => $q->whereRaw($hide))->count(), route('packaging.index'), null];
            }
            $tiles[] = [__('Packed today'), $todayEvents('packed')->count(), route('packaging.index'), null];
            if (! $shop) {
                $tiles[] = [__('I packed today'), $todayEvents('packed')->where('e.user_id', $user->id)->count(), route('packaging.index'), null];
                $tiles[] = [__('Waiting for the rider'), DB::table('orders')->whereIn('status_id', OrderStatus::idsFor(['packed', 'ready_for_pickup']))->count(), route('handover.index'), null];
                $tiles[] = [__('Returns to receive'), DB::table('orders')->where('status_id', OrderStatus::idFor('returned'))->whereNull('return_received_at')->count(), route('returns.index'), null];
                $notFound = DB::table('stock_issue_reports')->where('status', 'open')->count();
                $tiles[] = [__('Items not found'), $notFound, route('packaging.issues'), $notFound ? 'down' : null];
            }
        }
        if ($user->can('orders.view')) {
            $tiles[] = $shop
                ? [__('Delivered today'), $todayEvents('delivered')->count(), null, null]
                : [__('My orders delivered today'), $todayEvents('delivered')->join('orders as o', 'o.id', '=', 'e.order_id')->where('o.moderator_id', $user->id)->count(), null, null];
        }
        if ($user->can('points.manage')) {
            $open = DB::table('integrity_flags')->where('status', 'open')->count() + DB::table('point_ledger')->where('dispute_status', 'open')->count();
            $tiles[] = [__('Points to review'), $open, route('points.review'), $open ? 'down' : null];
        }
        $myPoints = (float) DB::table('point_ledger')->where('user_id', $user->id)->where('status', 'final')->where('created_at', '>=', now()->startOfMonth())->sum('points');

        // Out of stock, first thing, for the person who puts items back in stock (the packer: newest first; search;
        // pages). Managers and the owner get one tile with the count instead; the list is on Stock status.
        $outOfStock = null;
        if ($user->can('products.availability') && $shop) {
            $oos = \App\Models\ProductVariant::where('availability_status', 'out_of_stock')->whereHas('product', fn ($p) => $p->where('status', 'active'))->count();
            $tiles[] = [__('Out of stock'), $oos, route('products.availability'), $oos ? 'down' : null];
        } elseif ($user->can('products.availability')) {
            $sq = trim((string) $request->query('oos'));
            $outOfStock = \App\Models\ProductVariant::with('product:id,name')->where('availability_status', 'out_of_stock')
                ->whereHas('product', fn ($p) => $p->where('status', 'active'))
                ->when($sq !== '', fn ($w) => $w->where(fn ($s) => $s->where('sku', 'like', $sq.'%')->orWhere('name', 'like', '%'.$sq.'%')
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$sq.'%'))))
                ->orderByDesc('oos_marked_at')->orderByDesc('id')
                ->paginate(20, ['id', 'product_id', 'name', 'sku', 'oos_marked_at'], 'oos_page')->withQueryString()->fragment('out-of-stock');
        }

        return view('dashboard', ['tiles' => $tiles, 'myPoints' => $myPoints, 'outOfStock' => $outOfStock, 'myWorking' => DB::table('orders')->where('moderator_id', $user->id)
            ->whereIn('status_id', OrderStatus::idsFor(['new', 'record_verified']))->get(['id', 'order_no', 'ship_name'])]);
    }

    public function kpi(Request $request, KpiScorecard $kpi): View
    {
        [$from, $to] = $this->range($request);
        $mode = $request->query('view') === 'cohort' ? 'cohort' : 'day';
        $user = $request->user();
        $seeAll = $user->can('kpi.view');
        $canSetTargets = $seeAll && $user->can('settings.edit');
        $data = $kpi->build($from, $to, $mode);

        return view('reports.kpi', [
            // Without kpi.view a person sees only their own row next to the team average.
            'rows' => $seeAll ? $data['rows'] : array_values(array_filter($data['rows'], fn ($r) => $r['user_id'] === $user->id)),
            'team' => $data['team'],
            'from' => $from, 'to' => $to, 'mode' => $mode, 'seeAll' => $seeAll,
            'showAmount' => (bool) settings('kpi.show_delivered_amount'),
            'canSetTargets' => $canSetTargets,
            'targets' => $canSetTargets ? $kpi->targets() : [],
            'staff' => $canSetTargets ? User::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all() : [],
        ]);
    }

    public function analysis(Request $request, OrderProfit $profit): View
    {
        [$from, $to] = $this->range($request);
        $group = in_array($request->query('group'), OrderProfit::GROUPS, true) ? $request->query('group') : 'day';
        $channel = array_key_exists((string) $request->query('channel'), \App\Models\Order::CHANNELS) ? $request->query('channel') : null;
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $rows = $profit->grouped($group, $from, $to, $channel, $perPage);

        return view('reports.analysis', [
            'totals' => $profit->totals($from, $to, $channel),
            'missingCost' => $profit->missingCost($from, $to, $channel),
            'rows' => $rows,
            'moderators' => $group === 'moderator' ? DB::table('users')->whereIn('id', collect($rows->items())->pluck('g')->filter())->pluck('name', 'id') : collect(),
            'group' => $group, 'channel' => $channel, 'from' => $from, 'to' => $to, 'perPage' => $perPage,
            'seeProfit' => $request->user()->canSeeField('profit'),
            'seeCost' => $request->user()->canSeeField('cost_price'),
        ]);
    }

    /** @return array{0: string, 1: string} default: this month */
    private function range(Request $request): array
    {
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $from = $valid($request->query('from')) ? $request->query('from') : now()->startOfMonth()->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : today()->toDateString();

        return $from > $to ? [$to, $from] : [$from, $to];
    }
}
