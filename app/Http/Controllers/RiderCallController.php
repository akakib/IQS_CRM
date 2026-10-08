<?php

namespace App\Http\Controllers;

use App\Services\Work\RiderCallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Rider calls (the Communication window) and the Riders report. */
class RiderCallController extends Controller
{
    public function __construct(private RiderCallService $calls) {}

    public function find(Request $request): JsonResponse
    {
        $found = $this->calls->find((string) $request->query('q', ''));

        return response()->json($found ? ['found' => true] + $found : ['found' => false]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'], 'rider_name' => ['nullable', 'string', 'max:100'], 'rider_phone' => ['nullable', 'string', 'max:20'],
            'claim' => ['required', 'string'], 'verdict' => ['nullable', 'string'], 'action' => ['nullable', 'string'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->calls->record($request->user(), $data);

        return response()->json(['message' => __('Rider call saved.'), 'today' => DB::table('rider_calls')->where('handled_by', $request->user()->id)->where('created_at', '>=', today())->count()]);
    }

    /** Per rider: calls, parcels, what they said and how often it was not true. */
    public function report(Request $request): View
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = Carbon::parse($data['to'] ?? today())->min(today());
        $from = Carbon::parse($data['from'] ?? $to->copy()->subDays(29));
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $rows = DB::table('rider_calls as c')->leftJoin('riders as r', 'r.id', '=', 'c.rider_id')->whereBetween('c.created_at', $range)
            ->groupBy('c.rider_id', 'r.name', 'r.phone', 'c.claim', 'c.verdict')
            ->selectRaw('c.rider_id, r.name, r.phone, c.claim, c.verdict, COUNT(*) as n, COUNT(DISTINCT c.order_id) as parcels')->get();
        $parcels = DB::table('rider_calls')->whereBetween('created_at', $range)->groupBy('rider_id')->selectRaw('rider_id, COUNT(DISTINCT order_id) as n')->pluck('n', 'rider_id');
        $riders = $rows->groupBy(fn ($r) => (int) $r->rider_id)->map(function ($g, $id) use ($parcels) {
            $checked = $g->whereIn('verdict', ['true', 'false'])->sum('n');
            $false = $g->where('verdict', 'false')->sum('n');

            return [
                'name' => $g->first()->name ?? __('Rider not named'), 'phone' => $g->first()->phone,
                'calls' => (int) $g->sum('n'), 'parcels' => (int) ($id === 0 ? ($parcels[''] ?? 0) : ($parcels[$id] ?? 0)),
                'false' => (int) $false, 'unclear' => (int) $g->where('verdict', 'unclear')->sum('n'),
                'false_rate' => $checked ? (int) round($false * 100 / $checked) : null,
                'claims' => $g->groupBy('claim')->map(fn ($c) => (int) $c->sum('n'))->sortDesc(),
            ];
        })->sortByDesc('calls')->values();
        $byClaim = $rows->groupBy('claim')->map(fn ($g) => ['calls' => (int) $g->sum('n'), 'false' => (int) $g->where('verdict', 'false')->sum('n')])->sortByDesc('calls');
        $handlers = DB::table('rider_calls as c')->join('users as u', 'u.id', '=', 'c.handled_by')->whereBetween('c.created_at', $range)
            ->groupBy('u.name')->selectRaw('u.name, COUNT(*) as n, SUM(CASE WHEN c.action = ? THEN 1 ELSE 0 END) as solved', ['solved'])->orderByDesc('n')->get();

        return view('reports.riders', [
            'from' => $from, 'to' => $to, 'riders' => $riders, 'byClaim' => $byClaim, 'handlers' => $handlers,
            'total' => (int) $rows->sum('n'), 'claims' => RiderCallService::CLAIMS,
        ]);
    }
}
