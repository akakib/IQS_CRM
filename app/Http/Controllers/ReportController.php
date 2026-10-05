<?php

namespace App\Http\Controllers;

use App\Models\OrderStatus;
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
        $todayCount = fn (string $key) => DB::table('order_events')->where('to_status_id', OrderStatus::idFor($key))->whereBetween('created_at', [$today.' 00:00:00', $today.' 23:59:59'])->count();
        $tiles = [];

        if ($user->can('orders.view')) {
            $tiles[] = [__('Orders today'), DB::table('orders')->where('created_at', '>=', $today)->count(), route('orders.index'), null];
            $tiles[] = [__('Waiting to be taken'), DB::table('orders')->whereNull('owner_id')->where('status_id', OrderStatus::idFor('new'))->count(), route('orders.queue'), null];
            $tiles[] = [__('Confirmed today'), $todayCount('confirmed'), null, null];
            $tiles[] = [__('Open delivery issues'), DB::table('delivery_issues')->whereNull('resolved_at')->count(), route('issues.index'), null];
        }
        if ($user->can('packing.view')) {
            $tiles[] = [__('Packed today'), $todayCount('packed'), route('packing.index'), null];
        }
        if ($user->can('orders.view')) {
            $tiles[] = [__('Delivered today'), $todayCount('delivered'), null, null];
        }
        if ($user->can('points.manage')) {
            $open = DB::table('integrity_flags')->where('status', 'open')->count() + DB::table('point_ledger')->where('dispute_status', 'open')->count();
            $tiles[] = [__('Points to review'), $open, route('points.review'), $open ? 'down' : null];
        }
        $myPoints = (float) DB::table('point_ledger')->where('user_id', $user->id)->where('status', 'final')->where('created_at', '>=', now()->startOfMonth())->sum('points');

        return view('dashboard', ['tiles' => $tiles, 'myPoints' => $myPoints, 'myWorking' => DB::table('orders')->where('owner_id', $user->id)
            ->whereIn('status_id', OrderStatus::idsFor(['new', 'record_verified']))->get(['id', 'order_no', 'ship_name'])]);
    }

    public function kpi(Request $request, KpiScorecard $kpi): View
    {
        [$from, $to] = $this->range($request);

        return view('reports.kpi', ['rows' => $kpi->rows($from, $to), 'from' => $from, 'to' => $to,
            'weights' => ['volume' => settings('kpi.weight_volume'), 'speed' => settings('kpi.weight_speed'), 'quality' => settings('kpi.weight_quality')]]);
    }

    public function analysis(Request $request, OrderProfit $profit): View
    {
        [$from, $to] = $this->range($request);
        $group = in_array($request->query('group'), OrderProfit::GROUPS, true) ? $request->query('group') : 'day';
        $channel = in_array($request->query('channel'), ['web', 'messenger', 'whatsapp', 'phone', 'b2b'], true) ? $request->query('channel') : null;
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true) ? (int) $request->query('per_page') : 25;
        $rows = $profit->grouped($group, $from, $to, $channel, $perPage);

        return view('reports.analysis', [
            'totals' => $profit->totals($from, $to, $channel),
            'rows' => $rows,
            'owners' => $group === 'owner' ? DB::table('users')->whereIn('id', collect($rows->items())->pluck('g')->filter())->pluck('name', 'id') : collect(),
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
