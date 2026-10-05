<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Packing\ScanService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Rider handover: every parcel is scanned out; duplicates are caught, and
 * the manifest lists what was handed over and what was ready but missed.
 */
class HandoverController extends Controller
{
    public function index(): View
    {
        return view('handover.index', [
            'sessions' => DB::table('handover_sessions as s')->leftJoin('users as u', 'u.id', '=', 's.started_by')
                ->orderByDesc('s.id')->limit(15)->get(['s.*', 'u.name as by']),
            'waiting' => Order::whereIn('status_id', OrderStatus::idsFor(['packed', 'ready_for_pickup']))->count(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate(['rider_name' => ['nullable', 'string', 'max:100'], 'rider_phone' => ['nullable', 'string', 'max:20']]);
        $id = DB::table('handover_sessions')->insertGetId([
            'courier' => 'steadfast', 'pickup_date' => today(), 'rider_name' => $data['rider_name'] ?? null,
            'rider_phone' => Phone::normalize($data['rider_phone'] ?? null), 'started_by' => $request->user()->id, 'started_at' => now(),
        ]);

        return redirect()->route('handover.show', $id);
    }

    public function show(int $session): View
    {
        $s = DB::table('handover_sessions')->find($session);
        abort_unless($s, 404);

        return view('handover.show', ['session' => $s] + $this->manifestData($session));
    }

    public function scan(int $session, Request $request, ScanService $scans): JsonResponse
    {
        $s = DB::table('handover_sessions')->find($session);
        abort_unless($s && ! $s->closed_at, 422, __('This handover is closed.'));
        $data = $request->validate(['code' => ['required', 'string', 'max:80']]);

        return response()->json($scans->handover($session, $data['code'], $request->user()));
    }

    public function close(int $session, Request $request): RedirectResponse
    {
        $closed = DB::table('handover_sessions')->where('id', $session)->whereNull('closed_at')->update(['closed_at' => now(), 'closed_by' => $request->user()->id]);
        if ($closed) {
            // Packed but not scanned in this handover: they wait for the next pickup.
            Order::whereIn('status_id', OrderStatus::idsFor(['packed', 'ready_for_pickup']))
                ->where(fn ($q) => $q->whereNull('pickup_date')->orWhere('pickup_date', '<=', today()))
                ->update(['pickup_date' => today()->addDay()]);
        }

        return redirect()->route('handover.manifest', $session);
    }

    public function manifest(int $session): View
    {
        $s = DB::table('handover_sessions')->find($session);
        abort_unless($s, 404);

        return view('handover.manifest', ['session' => $s] + $this->manifestData($session));
    }

    /** @return array{handed: \Illuminate\Support\Collection, missing: \Illuminate\Support\Collection, bad: \Illuminate\Support\Collection} */
    private function manifestData(int $session): array
    {
        $handed = DB::table('scan_logs as l')->join('orders as o', 'o.id', '=', 'l.order_id')
            ->leftJoin('shipments as sh', 'sh.id', '=', 'o.active_shipment_id')
            ->where('l.handover_session_id', $session)->where('l.result', 'ok')
            ->orderBy('l.id')->get(['o.id', 'o.order_no', 'o.ship_name', 'o.ship_thana', 'o.cod_amount', 'sh.consignment_id', 'l.created_at']);

        return [
            'handed' => $handed,
            // Ready but not scanned in this handover.
            'missing' => Order::whereIn('status_id', OrderStatus::idsFor(['packed', 'ready_for_pickup']))->whereNotIn('id', $handed->pluck('id'))
                ->get(['id', 'order_no', 'ship_name', 'cod_amount']),
            'bad' => DB::table('scan_logs')->where('handover_session_id', $session)->where('result', '!=', 'ok')->orderByDesc('id')->limit(30)->get(),
        ];
    }
}
