<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The one search, open to every staff member (also from the top bar): find an order by its number,
 * the customer's phone, the courier CN or tracking code, or the customer's name. The customers it is
 * about come first (CustomerFinder), for those who may see customers.
 * Nothing is listed before a search. Phones follow each person's masking;
 * the order page opens only for those allowed to see it.
 */
class OrderSearchController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim(Phone::englishDigits((string) $request->query('q', '')));
        $by = in_array($request->query('by'), ['all', 'cn', 'phone', 'name'], true) ? $request->query('by') : 'all';
        $results = collect();
        $tooShort = $q !== '' && mb_strlen($q) < 3;

        if ($q !== '' && ! $tooShort) {
            $digits = preg_replace('/\D/', '', $q);
            $phone = Phone::normalize($q);
            $orderNo = strtoupper($q);
            if (preg_match('/^\d{4,7}$/', $q)) {
                $orderNo = 'IQ'.$q; // the number without IQ
            }
            $cnMatch = fn ($w) => $w->orWhereIn('orders.id', DB::table('shipments')->select('order_id')->where('consignment_id', $digits ?: $q)->orWhere('tracking_code', $q));
            $nameMatch = fn ($w) => $w->orWhere('ship_name', 'like', $q.'%')->orWhere('ship_name', 'like', '% '.$q.'%');
            $phoneMatch = fn ($w) => $w->orWhere('ship_phone', $phone ?: $q)->orWhere('ship_alt_phone', $phone ?: $q);
            $results = Order::query()
                ->with(['items:id,order_id,name_snapshot,qty,unit', 'moderator:id,name'])
                ->where(fn ($w) => match ($by) {
                    'cn' => $cnMatch($w),
                    'phone' => $phoneMatch($w),
                    'name' => $nameMatch($w),
                    // All: the order number, then whatever the text looks like.
                    default => $w->where('order_no', $orderNo)
                        ->when($phone, $phoneMatch)
                        ->when(strlen($digits) >= 6, $cnMatch)
                        ->orWhereIn('orders.id', DB::table('shipments')->select('order_id')->where('tracking_code', $q))
                        ->when(! $phone && strlen($digits) < 6, $nameMatch),
                })
                ->orderByDesc('orders.id')->limit(25)
                ->get(['orders.id', 'order_no', 'status_id', 'moderator_id', 'customer_id', 'ship_name', 'ship_phone', 'ship_thana', 'ship_district', 'grand_total', 'cod_amount', 'active_shipment_id', 'created_at', 'channel']);
        }

        // Parcels imported from a courier file (history only), found by their CN.
        $cn = $q !== '' && ! $tooShort && in_array($by, ['all', 'cn'], true) ? \App\Services\Customers\HistoryImport::cn($q) : null;
        $past = $cn ? DB::table('customer_past_orders as p')->join('customers as c', 'c.id', '=', 'p.customer_id')->where('p.cn', $cn)
            ->get(['p.customer_id', 'p.cn', 'p.source', 'p.ordered_at', 'p.amount', 'p.status', 'p.items', 'c.name', 'c.primary_phone']) : collect();
        $ids = $results->pluck('id');
        $user = $request->user();

        return view('orders.search', [
            // The customers the search is about, on top (only for those who may see customers).
            'customers' => $q !== '' && ! $tooShort && $user->can('customers.view') ? app(\App\Services\Customers\CustomerFinder::class)->find($q, $by) : collect(),
            'q' => $q,
            'by' => $by,
            'past' => $past,
            'tooShort' => $tooShort,
            'results' => $results,
            'statuses' => OrderStatus::map(),
            'cns' => $ids->isEmpty() ? collect() : DB::table('shipments')->whereIn('id', $results->pluck('active_shipment_id')->filter())->pluck('consignment_id', 'order_id'),
            // The order page opens only where the person may see the order.
            'openable' => $ids->isEmpty() || ! $user->can('orders.view') ? collect() : Order::visibleTo($user)->whereIn('id', $ids)->pluck('id')->flip(),
        ]);
    }
}
