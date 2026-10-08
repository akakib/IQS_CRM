<?php

namespace App\Http\Controllers;

use App\Models\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Packers: per packer over a range, days on duty, parcels packed, the
 * middle time to pack one, scan errors and returns blamed on packing, and
 * with the helpers they had, parcels per head. Five queries.
 */
class PackerReportController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = Carbon::parse($data['to'] ?? today())->min(today());
        $from = Carbon::parse($data['from'] ?? $to->copy()->subDays(6));
        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92);
        }
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $packs = DB::table('orders')->whereNotNull('packer_id')->whereBetween('packed_at', $range)->get(['packer_id', 'packaging_started_at', 'packed_at'])->groupBy('packer_id');
        $shifts = DB::table('packer_shifts')->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) as days, SUM(helper_count) as helper_days')->get()->keyBy('user_id');
        $errors = DB::table('scan_logs')->where('station', 'packaging')->whereIn('result', ['blocked', 'unknown'])->whereBetween('created_at', $range)
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) as n')->pluck('n', 'user_id');
        // Returned in the range with a reason that blames packing (wrong or missing item, damage...).
        $returned = DB::table('order_events as e')->join('orders as o', 'o.id', '=', 'e.order_id')->join('status_reasons as r', 'r.id', '=', 'e.reason_id')
            ->where('e.to_status_id', OrderStatus::idFor('returned'))->where('r.blame_stage', 'packaging')->whereNotNull('o.packer_id')->whereBetween('e.created_at', $range)
            ->groupBy('o.packer_id')->selectRaw('o.packer_id, COUNT(DISTINCT o.id) as n')->pluck('n', 'o.packer_id');
        $ids = $packs->keys()->merge($shifts->keys())->unique();
        $names = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');

        $rows = $ids->map(function ($id) use ($packs, $shifts, $errors, $returned, $names) {
            $mine = $packs->get($id, collect());
            $times = $mine->filter(fn ($o) => $o->packaging_started_at)->map(fn ($o) => max(0, Carbon::parse($o->packed_at)->getTimestamp() - Carbon::parse($o->packaging_started_at)->getTimestamp()))->sort()->values();
            $days = (int) ($shifts[$id]->days ?? 0);
            $helperDays = (int) ($shifts[$id]->helper_days ?? 0);
            $heads = $days + $helperDays; // person-days at the packing table

            return [
                'name' => $names[$id] ?? '#'.$id, 'packed' => $mine->count(), 'days' => $days, 'helper_days' => $helperDays,
                'helpers_avg' => $days ? round($helperDays / $days, 1) : 0,
                'per_head' => $heads ? round($mine->count() / $heads, 1) : null,
                'per_day' => $days ? round($mine->count() / $days, 1) : null,
                'median' => $times->isEmpty() ? null : (int) $times[intdiv($times->count(), 2)],
                'errors' => (int) ($errors[$id] ?? 0), 'returned' => (int) ($returned[$id] ?? 0),
            ];
        })->sortByDesc('packed')->values();

        return view('reports.packers', ['from' => $from, 'to' => $to, 'rows' => $rows]);
    }
}
