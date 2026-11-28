<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Packaging\ScanService;
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
    /** A packer (the same people as the simple Packaging page): one easy page, scan only, no by-hand ticks. */
    private function simple(\App\Models\User $u): bool
    {
        return ! $u->isOwner() && ! $u->can('packaging.manage') && ! $u->can('orders.edit') && $u->can('packaging.view');
    }

    /** Known riders, most recently used first. A new name typed is saved by start(). */
    private function riders(): \Illuminate\Support\Collection
    {
        return DB::table('riders')->orderByDesc('last_used_at')->orderBy('name')->limit(100)->get(['name', 'phone'])
            ->map(fn ($r) => ['name' => $r->name, 'phone' => (string) $r->phone])->values();
    }

    public function index(Request $request): View|RedirectResponse
    {
        if ($this->simple($request->user()) && $request->query('show') === 'earlier') {
            // Earlier handovers (owner 2026-11-29: packers see them too), today's finished ones included: newest first, a day to jump to, each opens its slip.
            $day = $request->validate(['day' => ['nullable', 'date']])['day'] ?? null;

            return view('handover.earlier', ['day' => $day, 'sessions' => DB::table('handover_sessions as s')->leftJoin('users as u', 'u.id', '=', 's.closed_by')
                ->whereNotNull('s.closed_at')
                ->when($day, fn ($q, $d) => $q->whereDate('s.closed_at', $d))
                ->orderByDesc('s.id')
                ->selectRaw("s.id, s.rider_name, s.rider_phone, s.closed_at, u.name as by_name, (SELECT COUNT(*) FROM scan_logs l WHERE l.handover_session_id = s.id AND l.result = 'ok') as parcels")
                ->paginate(20)->withQueryString()]);
        }
        if ($this->simple($request->user())) {
            // Today's open handover: straight back into it. One from an earlier day: it is finished first (owner 2026-11-29),
            // then a new one starts. None: the Ready list and the Scan button (which asks the rider first).
            $open = DB::table('handover_sessions')->whereNull('closed_at')->orderByDesc('id')->first(['id', 'rider_name', 'started_at']);
            if ($open && \Illuminate\Support\Carbon::parse($open->started_at)->isToday()) {
                return redirect()->route('handover.show', $open->id);
            }
            $stale = $open;
            if ($stale) {
                $stale->given = DB::table('scan_logs')->where('handover_session_id', $stale->id)->where('result', 'ok')->count();
            }

            // Today's finished handovers: any slip can be printed again (torn, lost). Earlier days are for a manager.
            $last = DB::table('handover_sessions as s')->whereNotNull('s.closed_at')->whereDate('s.closed_at', today())->orderByDesc('s.id')
                ->selectRaw("s.id, s.rider_name, s.closed_at, (SELECT COUNT(*) FROM scan_logs l WHERE l.handover_session_id = s.id AND l.result = 'ok') as parcels")->get();

            return view('handover.simple', ['session' => null, 'riders' => $this->riders(), 'last' => $last, 'stale' => $stale] + $this->manifestData(0));
        }
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]) + ['from' => null, 'to' => null];

        return view('handover.index', [
            'filters' => $filters,
            // Newest first; the date filter uses the indexed pickup_date.
            'sessions' => DB::table('handover_sessions as s')->leftJoin('users as u', 'u.id', '=', 's.started_by')
                ->when($filters['from'], fn ($q, $d) => $q->where('s.pickup_date', '>=', $d))
                ->when($filters['to'], fn ($q, $d) => $q->where('s.pickup_date', '<=', $d))
                ->orderByDesc('s.id')
                ->selectRaw("s.*, u.name as `by`, (SELECT COUNT(*) FROM scan_logs l WHERE l.handover_session_id = s.id AND l.result = 'ok') as parcels")
                ->paginate(15)->withQueryString(),
            'waiting' => Order::whereIn('status_id', OrderStatus::idsFor(['packed', 'ready_for_pickup']))->count(),
            'riders' => $this->riders(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate(['rider_name' => ['required', 'string', 'max:100'], 'rider_phone' => ['nullable', 'string', 'max:20']]);
        // One open handover at a time (owner 2026-11-29): the open one is finished first.
        $open = DB::table('handover_sessions')->whereNull('closed_at')->orderByDesc('id')->value('id');
        if ($open) {
            return redirect()->route($this->simple($request->user()) ? 'handover.index' : 'handover.show', $this->simple($request->user()) ? [] : $open)
                ->with('error', __('Finish the open handover first.'));
        }
        $this->remember($data['rider_name'], $data['rider_phone'] ?? null);
        $id = DB::table('handover_sessions')->insertGetId([
            'courier' => 'steadfast', 'pickup_date' => today(), 'rider_name' => $data['rider_name'] ?? null,
            'rider_phone' => Phone::normalize($data['rider_phone'] ?? null), 'started_by' => $request->user()->id, 'started_at' => now(),
        ]);

        // A packer goes straight to scanning: the camera opens by itself.
        return redirect()->route('handover.show', $this->simple($request->user()) ? ['session' => $id, 'scan' => 1] : $id);
    }

    /** Remember the rider (new name = new rider; a changed phone replaces the old one). */
    private function remember(string $name, ?string $phone): void
    {
        $name = trim($name);
        $phone = Phone::normalize($phone);
        if ($name === '') {
            return;
        }
        $rider = DB::table('riders')->where('name', $name)->first();
        $rider
            ? DB::table('riders')->where('id', $rider->id)->update(['phone' => $phone ?: $rider->phone, 'last_used_at' => now(), 'updated_at' => now()])
            : DB::table('riders')->insert(['name' => $name, 'phone' => $phone, 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** The wrong rider picked: another one, while nothing has been given yet (after that the record stands). */
    public function rider(int $session, Request $request): RedirectResponse
    {
        $data = $request->validate(['rider_name' => ['required', 'string', 'max:100'], 'rider_phone' => ['nullable', 'string', 'max:20']]);
        $s = DB::table('handover_sessions')->find($session);
        abort_unless($s && ! $s->closed_at, 422, __('This handover is closed.'));
        abort_if(DB::table('scan_logs')->where('handover_session_id', $session)->where('result', 'ok')->exists(), 422, __('Parcels were already given to this rider.'));
        $this->remember($data['rider_name'], $data['rider_phone'] ?? null);
        DB::table('handover_sessions')->where('id', $session)->update(['rider_name' => trim($data['rider_name']), 'rider_phone' => Phone::normalize($data['rider_phone'] ?? null)]);

        return redirect()->route('handover.show', $session);
    }

    public function show(int $session, Request $request): View
    {
        $s = DB::table('handover_sessions')->find($session);
        abort_unless($s, 404);
        if ($this->simple($request->user())) {
            return view('handover.simple', ['session' => $s, 'riders' => $s->closed_at ? collect() : $this->riders(), 'packers' => $this->packers($session)] + $this->manifestData($session));
        }

        return view('handover.show', ['session' => $s] + $this->manifestData($session));
    }

    public function scan(int $session, Request $request, ScanService $scans): JsonResponse
    {
        $s = DB::table('handover_sessions')->find($session);
        abort_unless($s && ! $s->closed_at, 422, __('This handover is closed.'));
        $data = $request->validate(['code' => ['required', 'string', 'max:80'], 'via' => ['nullable', 'in:camera,scanner,typed']]);
        $code = $this->labelFor($data['code']);
        if (is_array($code)) {
            return response()->json(['ok' => false, 'level' => 'red', 'result' => 'unknown', 'message' => $code['message']]);
        }

        return response()->json($scans->handover($session, $code, $request->user(), ($data['via'] ?? null) === 'typed'));
    }

    /** Manual tab: hand over the ticked parcels without scanning. Each one passes the same checks as a scan. */
    public function manual(int $session, Request $request, ScanService $scans): RedirectResponse
    {
        abort_if($this->simple($request->user()), 403, __('Scan each parcel. A manager hands over by hand.'));
        $s = DB::table('handover_sessions')->find($session);
        abort_unless($s && ! $s->closed_at, 422, __('This handover is closed.'));
        $data = $request->validate(['order_ids' => ['required', 'array', 'min:1', 'max:300'], 'order_ids.*' => ['integer']]);

        $done = 0;
        $refused = [];
        foreach (Order::whereIn('id', $data['order_ids'])->get() as $order) {
            $r = $scans->handoverByHand($session, $order, $request->user());
            $r['ok'] ? $done++ : $refused[] = $order->order_no.': '.$r['message'];
        }

        return redirect()->route('handover.show', ['session' => $session, 'tab' => 'manual'])
            ->with($done ? 'success' : 'error', trans_choice('{0} Nothing was handed over.|{1} :count parcel handed over by hand.|[2,*] :count parcels handed over by hand.', $done, ['count' => $done]))
            ->with('refused', $refused);
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

        // A packer stays on the same page: the summary and the slips to print for the rider to sign. Nothing given: no slip, a new one starts.
        if ($this->simple($request->user())) {
            $given = DB::table('scan_logs')->where('handover_session_id', $session)->where('result', 'ok')->exists();

            return $given ? redirect()->route('handover.show', $session) : redirect()->route('handover.index');
        }

        return redirect()->route('handover.manifest', $session);
    }

    /**
     * A torn barcode: the label no, the order no, or its last digits (3 to 6) among the parcels ready now.
     * The label code itself goes straight through (ScanService checks it).
     *
     * @return string|array{message: string}
     */
    private function labelFor(string $code): string|array
    {
        $code = strtoupper(trim($code));
        if (DB::table('shipment_labels')->where('barcode', $code)->exists()) {
            return $code;
        }
        $digits = preg_replace('/\D/', '', $code);
        $ready = DB::table('orders as o')->join('shipment_labels as l', fn ($j) => $j->on('l.order_id', '=', 'o.id')->whereNull('l.voided_at'))
            ->whereIn('o.status_id', OrderStatus::idsFor(['packed', 'ready_for_pickup']));
        $hits = (clone $ready)->where('o.order_no', $code)->pluck('l.barcode');
        if ($hits->isEmpty() && strlen($digits) >= 3 && strlen($digits) <= 6) {
            $hits = (clone $ready)->where('o.order_no', 'like', '%'.$digits)->limit(3)->pluck('l.barcode');
        }

        return match ($hits->count()) {
            1 => $hits->first(),
            0 => $code, // ScanService says Unknown label (and logs it)
            default => ['message' => __('More than one parcel ends in :d: type more digits.', ['d' => $digits])],
        };
    }

    /** Who scanned the parcels of this handover (the slip says who gave them). */
    private function packers(int $session): string
    {
        return DB::table('scan_logs as l')->join('users as u', 'u.id', '=', 'l.user_id')->where('l.handover_session_id', $session)->where('l.result', 'ok')
            ->distinct()->pluck('u.name')->implode(', ');
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
            ->orderBy('l.id')->get(['o.id', 'o.order_no', 'o.ship_name', 'o.ship_district', 'o.ship_thana', 'o.cod_amount', 'sh.consignment_id', 'l.created_at', 'l.manual']);

        return [
            'handed' => $handed,
            // Ready but not scanned in this handover.
            'missing' => DB::table('orders as o')->leftJoin('shipments as sh', 'sh.id', '=', 'o.active_shipment_id')
                ->whereIn('o.status_id', OrderStatus::idsFor(['packed', 'ready_for_pickup']))->whereNotIn('o.id', $handed->pluck('id'))
                ->orderBy('o.id')->limit(500)
                ->selectRaw('o.id, o.order_no, o.ship_name, o.ship_district, o.ship_thana, o.cod_amount, sh.consignment_id,
                    (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) as items')->get(),
            'bad' => DB::table('scan_logs')->where('handover_session_id', $session)->where('result', '!=', 'ok')->orderByDesc('id')->limit(30)->get(),
        ];
    }
}
