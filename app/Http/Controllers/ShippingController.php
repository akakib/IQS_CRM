<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Courier\BookingService;
use App\Services\Courier\CourierManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Courier desk: book Confirmed orders in bulk and print labels. */
class ShippingController extends Controller
{
    public function index(Request $request, CourierManager $courier): View
    {
        $orders = Order::query()
            ->select(['id', 'order_no', 'channel', 'status_id', 'ship_name', 'ship_thana', 'ship_district', 'cod_amount', 'total_weight_g', 'confirmed_at', 'packed_version', 'current_version', 'edited_after_pack', 'is_duplicate_flag'])
            ->where('status_id', OrderStatus::idFor('confirmed'))
            ->orderBy('confirmed_at')->limit(500)->get();

        $booked = Order::query()
            ->select(['id', 'order_no', 'status_id', 'ship_name', 'cod_amount', 'label_version', 'current_version', 'packed_version', 'edited_after_pack', 'is_duplicate_flag', 'active_shipment_id'])
            ->where('status_id', OrderStatus::idFor('ready_for_packaging'))->orderByDesc('id')->limit(200)->get();
        $consignments = DB::table('shipments')->whereIn('id', $booked->pluck('active_shipment_id')->filter())->pluck('consignment_id', 'id');

        return view('shipping.index', [
            'orders' => $orders,
            'booked' => $booked,
            'consignments' => $consignments,
            'statuses' => OrderStatus::map(),
            'courier' => $courier->resolvedName(),
        ]);
    }

    public function book(Request $request, BookingService $booking): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:300'], 'ids.*' => ['integer']]);
        // Same path as every other booking (claimed first), so this button and an automatic
        // booking running at the same moment can never send one order twice.
        DB::table('orders')->whereIn('id', $data['ids'])->where('status_id', \App\Models\OrderStatus::idFor('confirmed'))->whereNull('booking_claim')
            ->update(['booking_state' => 'queued', 'booking_attempts' => 0, 'book_after' => null, 'booking_error' => null]);
        app(\App\Services\Orders\DeskService::class)->runBookings($data['ids'], $request->user()->id);
        $rows = DB::table('orders')->whereIn('id', $data['ids'])->get(['order_no', 'status_id', 'booking_error']);
        $result = [
            'booked' => $rows->where('status_id', '!=', \App\Models\OrderStatus::idFor('confirmed'))->pluck('order_no')->all(),
            'failed' => $rows->where('status_id', \App\Models\OrderStatus::idFor('confirmed'))->mapWithKeys(fn ($r) => [$r->order_no => $r->booking_error ?: __('Not booked')])->all(),
        ];

        $message = trans_choice(':count order booked.|:count orders booked.', count($result['booked']), ['count' => count($result['booked'])]);
        // Stay on Courier booking (the failures stay visible); the labels open in their own tab from the banner.
        $redirect = redirect()->to(route('shipping.index').($result['booked'] ? '#booked' : ''));
        if ($result['booked']) {
            session()->flash('print_labels', route('shipping.labels', ['orders' => implode(',', $result['booked'])]));
        }

        return $redirect->with($result['failed'] ? 'error' : 'success', $result['failed']
            ? $message.' '.__('Failed: :f', ['f' => collect($result['failed'])->map(fn ($e, $no) => "{$no} ({$e})")->join('; ')])
            : $message);
    }

    /** Printable labels: ?orders=IQ10001,IQ10002 (order numbers). */
    public function labels(Request $request): View
    {
        $numbers = array_filter(array_map('trim', explode(',', (string) $request->query('orders'))));
        $orders = Order::whereIn('order_no', $numbers)->with('items:id,order_id,name_snapshot,qty,unit')->get()->sortBy(fn ($o) => array_search($o->order_no, $numbers));
        $labels = DB::table('shipment_labels as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')
            ->whereIn('l.order_id', $orders->pluck('id'))->whereNull('l.voided_at')
            ->get(['l.order_id', 'l.barcode', 'l.cod_on_label', 's.consignment_id', 's.courier'])->keyBy('order_id');

        DB::table('shipment_labels')->whereIn('order_id', $orders->pluck('id'))->whereNull('voided_at')->whereNull('printed_at')
            ->update(['printed_at' => now(), 'printed_by' => $request->user()->id]);

        return view('shipping.labels', ['orders' => $orders, 'labels' => $labels]);
    }

    /** Webhook fallback, on demand. */
    public function resync(): RedirectResponse
    {
        \Illuminate\Support\Facades\Artisan::call('courier:resync');

        return back()->with('success', trim(\Illuminate\Support\Facades\Artisan::output()));
    }

    /** New label for the current version (after an address/COD change); voids the old one. */
    public function reprint(Order $order, Request $request, BookingService $booking): RedirectResponse
    {
        abort_unless($order->active_shipment_id, 422, __('This order is not booked.'));
        $barcode = $booking->issueLabel($order, $order->active_shipment_id, $request->user(), __('Reprinted for version :v', ['v' => $order->current_version]));

        return redirect()->to(route('shipping.index').'#booked')->with('print_labels', route('shipping.labels', ['orders' => $order->order_no]))
            ->with('success', __('New label :b. The old one no longer scans.', ['b' => $barcode]));
    }
}
