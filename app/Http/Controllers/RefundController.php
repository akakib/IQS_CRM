<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Refund;
use App\Models\StatusReason;
use App\Services\Complaints\RefundService;
use App\Support\Lists\ListState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RefundController extends Controller
{
    public function __construct(private RefundService $refunds) {}

    /** One list query plus one grouped count. */
    public function index(Request $request): View
    {
        $list = ListState::from($request, ['id', 'amount'], [
            'tab' => array_merge(Refund::STATUSES, ['all']),
            'method' => 'int',
            'from' => 'date',
            'to' => 'date',
        ], 'desc');
        $tab = $list->filter('tab') ?? 'pending';
        $q = trim($list->search);
        $digits = preg_replace('/\D/', '', $q);

        $refunds = DB::table('refunds as f')->join('orders as o', 'o.id', '=', 'f.order_id')
            ->join('payment_methods as m', 'm.id', '=', 'f.method_id')
            ->leftJoin('status_reasons as r', 'r.id', '=', 'f.reason_id')
            ->leftJoin('users as rq', 'rq.id', '=', 'f.requested_by')
            ->leftJoin('users as dc', 'dc.id', '=', 'f.decided_by')
            ->select(['f.id', 'f.order_id', 'f.complaint_id', 'f.amount', 'f.status', 'f.recipient_number', 'f.note', 'f.transaction_id', 'f.decision_note', 'f.created_at', 'f.requested_by',
                'o.order_no', 'o.ship_name', 'm.name as method', 'm.requires_trx_id', 'r.label_en as reason', 'rq.name as requester', 'dc.name as decider'])
            ->when($tab !== 'all', fn ($w) => $w->where('f.status', $tab))
            ->when($list->filter('method'), fn ($w, $id) => $w->where('f.method_id', $id))
            ->when($list->filter('from'), fn ($w, $d) => $w->where('f.created_at', '>=', $d.' 00:00:00'))
            ->when($list->filter('to'), fn ($w, $d) => $w->where('f.created_at', '<=', $d.' 23:59:59'))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s
                ->where('o.order_no', strtoupper($q))
                ->when(strlen($digits) >= 4, fn ($s) => $s->orWhere('o.ship_phone', 'like', (str_starts_with($digits, '0') ? $digits : '0'.$digits).'%')->orWhere('f.recipient_number', 'like', $digits.'%'))
                ->orWhere('o.ship_name', 'like', $q.'%')))
            ->orderBy('f.'.$list->sort, $list->dir)->orderBy('f.id', $list->dir)
            ->simplePaginate($list->perPage)
            ->withQueryString();

        $counts = DB::table('refunds')->groupBy('status')->selectRaw('status, COUNT(*) as n, SUM(amount) as amount')->get()->keyBy('status');

        return view('refunds.index', [
            'refunds' => $refunds, 'list' => $list, 'tab' => $tab, 'counts' => $counts,
            'methods' => DB::table('payment_methods')->where('is_active', true)->orderBy('id')->pluck('name', 'id')->all(),
            'canApprove' => $request->user()->can('refunds.approve'),
            'canPay' => $request->user()->can('refunds.create'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'complaint_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'method_id' => ['required', 'integer'],
            'recipient_number' => ['nullable', 'string', 'max:20'],
            'reason_id' => ['nullable', 'integer', 'exists:status_reasons,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $order = Order::visibleTo($request->user())->findOrFail($data['order_id']);
        $this->refunds->request($order, $data, $request->user());

        return back()->with('success', __('Refund requested. A manager has to approve it.'));
    }

    public function decide(Refund $refund, Request $request): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->refunds->decide($refund, $data['decision'] === 'approve', $data['note'] ?? null, $request->user());

        return back()->with('success', $data['decision'] === 'approve' ? __('Refund approved. Mark it as paid once the money is sent.') : __('Refund rejected.'));
    }

    public function paid(Refund $refund, Request $request): RedirectResponse
    {
        $data = $request->validate(['transaction_id' => ['nullable', 'string', 'max:100']]);
        $this->refunds->markPaid($refund, $data['transaction_id'] ?? null, $request->user());

        return back()->with('success', __('Refund marked as paid.'));
    }

    /** Refund reasons for forms (shared by the order and complaint pages). */
    public static function reasonOptions(): array
    {
        return StatusReason::options('refund');
    }
}
