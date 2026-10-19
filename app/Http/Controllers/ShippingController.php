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
        $list = \App\Support\Lists\ListState::from($request, ['id'], [], 'asc');
        $q = trim($list->search);
        $digits = preg_replace('/\D/', '', $q);
        // One search for both lists: order no, phone, or area.
        $find = fn ($w) => $w->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('order_no', strtoupper($q))->orWhere('ship_district', 'like', '%'.$q.'%')
            ->orWhere('ship_thana', 'like', '%'.$q.'%')->when($digits, fn ($d) => $d->orWhere('ship_phone', 'like', $digits.'%'))));
        $orders = Order::query()
            ->select(['id', 'order_no', 'channel', 'status_id', 'ship_name', 'ship_thana', 'ship_district', 'cod_amount', 'total_weight_g', 'confirmed_at', 'packed_version', 'current_version', 'edited_after_pack', 'is_duplicate_flag', 'duplicate_of_no', 'duplicate_items', 'duplicate_address'])
            ->where('status_id', OrderStatus::idFor('confirmed'))->tap($find)
            ->orderBy('confirmed_at')->paginate($list->perPage, ['*'], 'ready_page')->withQueryString()->fragment('book');

        $booked = Order::query()
            ->select(['id', 'order_no', 'status_id', 'ship_name', 'cod_amount', 'label_version', 'current_version', 'packed_version', 'edited_after_pack', 'is_duplicate_flag', 'duplicate_of_no', 'duplicate_items', 'duplicate_address', 'active_shipment_id'])
            ->where('status_id', OrderStatus::idFor('ready_for_packaging'))->tap($find)->orderByDesc('id')
            ->paginate($list->perPage, ['*'], 'booked_page')->withQueryString()->fragment('booked');
        $consignments = DB::table('shipments')->whereIn('id', $booked->pluck('active_shipment_id')->filter())->pluck('consignment_id', 'id');

        return view('shipping.index', [
            'orders' => $orders,
            'booked' => $booked,
            'list' => $list,
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

    /** Printable labels: ?orders=IQ10001,IQ10002 (order numbers). Same print path as Packaging (current label, one print run). */
    public function labels(Request $request, \App\Services\Packaging\LabelPrinter $printer): View|RedirectResponse
    {
        $numbers = collect(explode(',', (string) $request->query('orders')))->map('trim')->filter()->take(100);
        $ids = Order::whereIn('order_no', $numbers)->pluck('id', 'order_no');
        $print = $printer->picked($numbers->map(fn ($no) => $ids[$no] ?? 0), $request->user(), respectPause: false);

        return $print ? view('shipping.labels', $print) : redirect()->route('shipping.index')->with('error', __('These orders have no label to print.'));
    }

    /** Webhook fallback, on demand. */
    /** When the courier was last asked (the Re-sync button shows it). */
    public const RESYNC_AT = 'courier-resync-at';

    /** Ask the courier now about every parcel on its way (booking, Parcel status and Delivery issues people); once a minute at most. */
    public function resync(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('shipping.create') || $request->user()->can('hotline.view'), 403);
        if (! \Illuminate\Support\Facades\Cache::add('courier-resync-lock', 1, 60)) {
            return back()->with('success', __('Checked less than a minute ago. Try again in a minute.'));
        }
        \Illuminate\Support\Facades\Artisan::call('courier:resync');
        \Illuminate\Support\Facades\Cache::forever(self::RESYNC_AT, now()->toIso8601String());
        // The command prints "Checked N parcels, M changed." and "Failed: ..."; the toast says it in plain words.
        $out = \Illuminate\Support\Facades\Artisan::output();
        preg_match('/Checked (\d+) parcels, (\d+) changed/', $out, $m);
        preg_match('/Failed: (.+)/', $out, $f);
        $message = $m ? __(':n parcels checked, :c updated.', ['n' => $m[1], 'c' => $m[2]]) : __('Courier status checked.');
        if ($f) {
            return back()->with('error', $message.' '.__('The courier did not answer for :o.', ['o' => trim($f[1])]));
        }

        return back()->with('success', $message);
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
