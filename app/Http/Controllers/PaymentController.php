<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Orders\OrderService;
use App\Support\Lists\ListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Payments to check: every advance with its TrxID and amount in one list, so
 * a person compares them with the bKash / Nagad statement and ticks them off
 * together instead of opening orders one by one.
 *
 * Page load: user + permissions (2), list (1), count + sum (1), methods (1).
 */
class PaymentController extends Controller
{

    public function __construct(private OrderService $orders) {}

    public function index(Request $request): View
    {
        $this->authorizeCheck($request);
        $list = ListState::from($request, ['received_at', 'amount'], [
            'status' => ['check', 'verified', 'rejected', 'all'], 'method' => 'int', 'from' => 'date', 'to' => 'date',
        ], 'desc');
        $status = $list->filter('status') ?? 'check';
        $q = $list->search;

        $base = DB::table('order_payments as p')->join('orders as o', 'o.id', '=', 'p.order_id')
            ->whereIn('p.payment_type', ['advance', 'adjustment'])
            ->when($status !== 'all', fn ($w) => $w->where('p.status', ['check' => 'pending_verification', 'verified' => 'verified', 'rejected' => 'rejected'][$status]))
            ->when($list->filter('method'), fn ($w, $m) => $w->where('p.method_id', $m))
            ->when($list->filter('from'), fn ($w, $d) => $w->where('p.received_at', '>=', $d.' 00:00:00'))
            ->when($list->filter('to'), fn ($w, $d) => $w->where('p.received_at', '<=', $d.' 23:59:59'))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('p.transaction_id', $q)->orWhere('o.order_no', strtoupper($q))
                ->orWhere('o.ship_phone', 'like', preg_replace('/\D/', '', $q).'%')->orWhere('p.sender_number', 'like', preg_replace('/\D/', '', $q).'%')));

        $summary = (clone $base)->selectRaw('COUNT(*) as n, COALESCE(SUM(p.amount), 0) as total')->first();
        $payments = (clone $base)->join('payment_methods as m', 'm.id', '=', 'p.method_id')->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->leftJoin('users as v', 'v.id', '=', 'p.verified_by')
            ->orderBy($list->sort === 'amount' ? 'p.amount' : 'p.received_at', $list->dir)->orderBy('p.id', $list->dir)
            ->select(['p.id', 'p.amount', 'p.transaction_id', 'p.sender_number', 'p.status', 'p.counts_now', 'p.received_at', 'p.verified_at',
                'o.id as order_id', 'o.order_no', 'o.ship_name', 'o.ship_phone', 'm.name as method', 'u.name as added_by', 'v.name as checked_by'])
            ->paginate($list->perPage)->withQueryString();

        return view('payments.index', [
            'list' => $list, 'status' => $status, 'payments' => $payments, 'summary' => $summary,
            'methods' => DB::table('payment_methods')->orderBy('id')->pluck('name', 'id')->all(),
        ]);
    }

    /** Verify or reject the ticked payments (each one only if it is still waiting). */
    public function decide(Request $request): RedirectResponse
    {
        $this->authorizeCheck($request);
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['integer'], 'decision' => ['required', 'in:approve,reject']]);
        $rows = DB::table('order_payments')->whereIn('id', $data['ids'])->where('status', 'pending_verification')->get(['id', 'order_id']);
        $done = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            try {
                $this->orders->verifyPayment(Order::findOrFail($row->order_id), $row->id, $data['decision'] === 'approve', $request->user());
                $done++;
            } catch (\Illuminate\Validation\ValidationException) {
                $skipped++; // someone else checked it meanwhile: counted once, by them
            }
        }

        return back()->with('success', trans_choice($data['decision'] === 'approve' ? ':count payment verified.|:count payments verified.' : ':count payment rejected.|:count payments rejected.', $done, ['count' => $done])
            .($skipped ? ' '.__(':n already checked by someone else.', ['n' => $skipped]) : ''));
    }

    private function authorizeCheck(Request $request): void
    {
        abort_unless($request->user()->can('payments.verify') || $request->user()->can('orders.approve'), 403);
    }
}
