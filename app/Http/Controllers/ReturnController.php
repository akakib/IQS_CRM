<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Packaging\ReturnService;
use App\Support\Lists\ListState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Returns: parcels the courier sent back. Scan one (label, CN or order no),
 * mark each item Good / Damaged / Not in the parcel, Save. To receive lists
 * what is still on its way back (red after the setting's days); Received
 * lists what came back and how.
 */
class ReturnController extends Controller
{
    public function __construct(private ReturnService $returns) {}

    public function index(Request $request): View
    {
        $list = ListState::from($request, ['id'], ['tab' => ['waiting', 'received']], 'desc');
        $tab = $list->filter('tab') ?? 'waiting';
        $q = trim($list->search);
        $digits = \App\Support\Phone::searchPrefix($q);
        $states = OrderStatus::idsFor(ReturnService::STATES);

        $base = DB::table('orders as o')->whereIn('o.status_id', $states)
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => \App\Support\Phone::orWhereOrder($s->where('o.order_no', strtoupper($q)), $digits)));
        $rows = (clone $base)->when($tab === 'waiting', fn ($w) => $w->whereNull('o.return_received_at'), fn ($w) => $w->whereNotNull('o.return_received_at'))
            ->leftJoin('users as u', 'u.id', '=', 'o.return_received_by')->leftJoin('shipments as s', 's.id', '=', 'o.active_shipment_id')
            ->orderByDesc($tab === 'waiting' ? 'o.id' : 'o.return_received_at')
            ->select(['o.id', 'o.order_no', 'o.ship_name', 'o.ship_phone', 'o.status_id', 'o.grand_total', 'o.return_received_at', 'u.name as receiver', 's.consignment_id'])
            ->paginate($list->perPage)->withQueryString();
        $returnedAt = $this->returns->returnedAt($rows->pluck('id')->all());
        $received = $tab === 'received' ? DB::table('return_items')->whereIn('order_id', $rows->pluck('id'))->groupBy('order_id', 'condition')
            ->selectRaw('order_id, `condition` as c, SUM(qty) as n')->get()->groupBy('order_id') : collect();

        // The reason each one came back with (from the courier: "not set yet" until someone sets it).
        $reasonOf = DB::table('order_events')->whereIn('order_id', $rows->pluck('id'))->whereIn('to_status_id', $states)
            ->orderBy('id')->pluck('reason_id', 'order_id');

        return view('returns.index', [
            'list' => $list, 'tab' => $tab, 'rows' => $rows, 'returnedAt' => $returnedAt, 'received' => $received, 'reasonOf' => $reasonOf,
            'reasons' => \App\Models\StatusReason::where('reason_type', 'return')->where('is_active', true)->where('system_key', '!=', 'unclassified')
                ->orderBy('sort_order')->pluck('label_en', 'id')->map(fn ($l) => __($l))->all(),
            'unclassified' => (int) DB::table('status_reasons')->where('reason_type', 'return')->where('system_key', 'unclassified')->value('id'),
            'waitingCount' => (clone $base)->whereNull('o.return_received_at')->count(),
            'alertDays' => (int) settings('returns.alert_days'), 'statuses' => OrderStatus::map(),
        ]);
    }

    /** A scan: the returned parcel and its items, ready to mark. */
    public function find(Request $request): JsonResponse
    {
        $order = $this->returns->find((string) $request->input('code'));
        if (! $order) {
            return response()->json(['ok' => false, 'level' => 'red', 'message' => __('No order with this label, CN or number.')]);
        }
        $key = OrderStatus::map()[$order->status_id]['key'];
        if (! in_array($key, ReturnService::STATES, true)) {
            return response()->json(['ok' => false, 'level' => 'red', 'message' => __(':no is :s, not a return. Do not take it in.', ['no' => $order->order_no, 's' => __(OrderStatus::map()[$order->status_id]['name'])])]);
        }
        if ($order->return_received_at) {
            return response()->json(['ok' => false, 'level' => 'orange', 'message' => __(':no was already received on :d.', ['no' => $order->order_no, 'd' => $order->return_received_at->format('d M')])]);
        }

        return response()->json(['ok' => true, 'level' => 'ok', 'message' => __(':no: mark each item.', ['no' => $order->order_no]), 'order' => [
            'id' => $order->id, 'order_no' => $order->order_no, 'customer' => $order->ship_name, 'partial' => $key === 'partial_delivered',
            // The pack each one was sold in ("1 kg"): the amounts are typed in that unit.
            'items' => DB::table('order_items as i')->leftJoin('product_variants as v', 'v.id', '=', 'i.variant_id')->where('i.order_id', $order->id)
                ->get(['i.id', 'i.name_snapshot', 'i.qty', 'i.unit', 'v.name as pack'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name_snapshot, 'qty' => (float) $i->qty, 'unit' => $i->unit,
                    'pack' => $i->pack && ! in_array(strtolower($i->pack), ['default', ''], true) && ! str_contains($i->name_snapshot, $i->pack) ? $i->pack : null])->values(),
        ]]);
    }

    public function receive(Request $request, Order $order): JsonResponse
    {
        $states = implode(',', array_keys(ReturnService::CONDITIONS));
        $data = $request->validate(['items' => ['required', 'array']]);
        foreach ($data['items'] as $line) {
            $ok = is_array($line)
                ? collect($line)->every(fn ($q, $state) => in_array($state, array_keys(ReturnService::CONDITIONS), true) && is_numeric($q) && $q >= 0)
                : in_array($line, explode(',', $states), true);
            abort_unless($ok, 422);
        }
        $count = $this->returns->receive($order, $data['items'], $request->user());
        $n = fn (float $v) => $v + 0;

        return response()->json(['ok' => true, 'message' => __(':no received: :g good, :d damaged, :m not in the parcel.', [
            'no' => $order->order_no, 'g' => $n($count['good']), 'd' => $n($count['damaged']), 'm' => $n($count['missing']),
        ]).($count['kept'] ? ' '.__(':k kept by the customer.', ['k' => $n($count['kept'])]) : '')]);
    }
}
