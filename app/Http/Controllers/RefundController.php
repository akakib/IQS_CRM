<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Orders\RefundService;
use App\Support\Lists\ListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Payments > Refunds: orders cancelled or returned after the customer paid.
 * Owed: give it back (how, TrxID) or keep it as credit for their next order.
 * Done: what was given back or kept, and by whom.
 */
class RefundController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeCheck($request);
        $list = ListState::from($request, ['updated_at', 'refund_due'], ['tab' => ['owed', 'done']], 'desc');
        $tab = $list->filter('tab') ?? 'owed';
        $q = trim($list->search);
        $digits = preg_replace('/\D/', '', $q);

        $owed = DB::table('orders as o')->where('o.refund_due', '>', 0)
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('o.order_no', strtoupper($q))->when($digits, fn ($d) => $d->orWhere('o.ship_phone', 'like', $digits.'%'))))
            ->orderByDesc('o.updated_at')
            ->select(['o.id', 'o.order_no', 'o.ship_name', 'o.ship_phone', 'o.status_id', 'o.refund_due', 'o.advance_verified', 'o.customer_id', 'o.updated_at']);
        $done = DB::table('order_payments as p')->join('orders as o', 'o.id', '=', 'p.order_id')->join('payment_methods as m', 'm.id', '=', 'p.method_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')->where('p.payment_type', 'refund')
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('o.order_no', strtoupper($q))->orWhere('p.transaction_id', $q)->when($digits, fn ($d) => $d->orWhere('o.ship_phone', 'like', $digits.'%'))))
            ->orderByDesc('p.id')
            ->select(['p.id', 'p.amount', 'p.transaction_id', 'p.note', 'p.created_at', 'm.system_key', 'm.name as method', 'u.name as person', 'o.id as order_id', 'o.order_no', 'o.ship_name', 'o.ship_phone']);

        return view('payments.refunds', [
            'list' => $list, 'tab' => $tab,
            'rows' => ($tab === 'done' ? $done : $owed)->paginate($list->perPage)->withQueryString(),
            'owedCount' => DB::table('orders')->where('refund_due', '>', 0)->selectRaw('COUNT(*) as n, COALESCE(SUM(refund_due), 0) as total')->first(),
            'methods' => DB::table('payment_methods')->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all(),
            'statuses' => \App\Models\OrderStatus::map(),
        ]);
    }

    public function refund(Request $request, Order $order, RefundService $refunds): RedirectResponse
    {
        $this->authorizeCheck($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'], 'method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'transaction_id' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $refunds->refund($order, (float) $data['amount'], (int) $data['method_id'], $data['transaction_id'] ?? null, $data['note'] ?? null, $request->user());

        return back()->with('success', __(':no: ৳:a given back.', ['no' => $order->order_no, 'a' => number_format((float) $data['amount'])]));
    }

    public function credit(Request $request, Order $order, RefundService $refunds): RedirectResponse
    {
        $this->authorizeCheck($request);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);
        $refunds->keepAsCredit($order, (float) $data['amount'], $request->user());

        return back()->with('success', __(':no: ৳:a kept as credit for the next order.', ['no' => $order->order_no, 'a' => number_format((float) $data['amount'])]));
    }

    private function authorizeCheck(Request $request): void
    {
        abort_unless($request->user()->can('payments.verify') || $request->user()->can('orders.approve'), 403);
    }
}
