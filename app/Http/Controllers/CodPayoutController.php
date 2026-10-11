<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\Courier\PayoutImporter;
use App\Support\Lists\ListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Payments > COD from courier: is the money the courier collected actually
 * paid to us? Waiting = delivered with no statement line yet; Mismatch = the
 * statement paid a different amount; Paid = matched. Statements are CSV files
 * saved from the courier panel.
 */
class CodPayoutController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeCheck($request);
        $list = ListState::from($request, ['final_at'], ['tab' => ['waiting', 'mismatch', 'paid', 'statements']], 'asc');
        $tab = $list->filter('tab') ?? 'waiting';
        $q = strtoupper(trim($list->search));

        $base = fn () => DB::table('shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')
            ->whereNotNull('s.final_at')->where('s.collected_amount', '>', 0);
        $mismatch = fn ($w) => $w->whereNotNull('s.payout_id')->whereRaw('ABS(s.payout_collected - s.collected_amount) >= 1');
        $query = match ($tab) {
            'mismatch' => $mismatch($base()),
            'paid' => $base()->whereNotNull('s.payout_id')->whereRaw('ABS(s.payout_collected - s.collected_amount) < 1'),
            default => $base()->whereNull('s.payout_id'),
        };
        $query->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('o.order_no', $q)->orWhere('s.consignment_id', $q)->orWhere('s.tracking_code', $q)))
            ->leftJoin('courier_payouts as p', 'p.id', '=', 's.payout_id')
            ->orderBy('s.final_at', $tab === 'paid' ? 'desc' : 'asc')
            ->select(['s.id', 's.consignment_id', 's.collected_amount', 's.delivery_charge', 's.payout_collected', 's.payout_charge', 's.final_at',
                'o.id as order_id', 'o.order_no', 'o.ship_name', 'p.paid_on', 'p.reference']);

        $waiting = $base()->whereNull('s.payout_id')->selectRaw('COUNT(*) as n, COALESCE(SUM(s.collected_amount), 0) as collected, COALESCE(SUM(s.delivery_charge), 0) as charge')->first();

        return view('payments.cod', [
            'list' => $list, 'tab' => $tab, 'waiting' => $waiting,
            'mismatchCount' => $mismatch($base())->count(),
            'rows' => $tab === 'statements'
                ? DB::table('courier_payouts as p')->leftJoin('users as u', 'u.id', '=', 'p.uploaded_by')->orderByDesc('p.paid_on')->orderByDesc('p.id')->select(['p.*', 'u.name as by'])->paginate($list->perPage)->withQueryString()
                : $query->paginate($list->perPage)->withQueryString(),
            'lateDays' => 7,
        ]);
    }

    public function upload(Request $request, PayoutImporter $importer, ActivityLogger $logger): RedirectResponse
    {
        $this->authorizeCheck($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $r = $importer->import($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName(), $data['paid_on'], $data['reference'] ?? null, $request->user());
        $logger->log('courier_payout.imported', ['courier_payout', $r['payout_id']], null, collect($r)->except('unknown_ids')->all());

        $message = __(':m parcels matched, :x paid a different amount, :u not found.', ['m' => $r['matched'], 'x' => $r['mismatched'], 'u' => $r['unknown']]);
        if ($r['unknown_ids']) {
            $message .= ' '.__('Not found: :ids', ['ids' => implode(', ', array_slice($r['unknown_ids'], 0, 10)).(count($r['unknown_ids']) > 10 ? '…' : '')]);
        }

        return redirect()->route('cod.index', ['tab' => $r['mismatched'] ? 'mismatch' : 'waiting'])->with($r['mismatched'] || $r['unknown'] ? 'error' : 'success', $message);
    }

    private function authorizeCheck(Request $request): void
    {
        abort_unless($request->user()->can('payments.verify') || $request->user()->can('orders.approve'), 403);
    }
}
