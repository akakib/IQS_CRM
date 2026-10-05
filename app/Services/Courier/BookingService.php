<?php

namespace App\Services\Courier;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Courier\Data\BookingRequest;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Bulk courier booking for Confirmed orders. Invoice = our order number, so a
 * repeated booking returns the same consignment. Each booked order gets an
 * active shipment, a label (barcode = order no + version) and moves to
 * Ready for packaging; that move is only possible with a consignment ID.
 */
class BookingService
{
    public function __construct(private CourierManager $courier, private OrderStateMachine $machine, private OrderService $orders) {}

    /**
     * @param  list<int>  $orderIds
     * @return array{booked: list<string>, failed: array<string, string>}
     */
    public function book(array $orderIds, ?User $by): array
    {
        $confirmed = OrderStatus::idFor('confirmed');
        $orders = Order::whereIn('id', $orderIds)->where('status_id', $confirmed)->get()->keyBy('order_no');
        $hotline = (string) settings('store.hotline');
        $result = ['booked' => [], 'failed' => []];

        $requests = $orders->map(fn (Order $o) => new BookingRequest(
            invoice: $o->order_no,
            recipientName: $o->ship_name,
            recipientPhone: $o->ship_phone,
            recipientAddress: collect([$o->ship_address, $o->ship_thana, $o->ship_district])->filter()->join(', '),
            codAmount: (float) $o->cod_amount,
            note: $hotline ? __('Any problem: call :h', ['h' => $hotline]) : null,
        ))->values()->all();

        if ($requests === []) {
            return $result;
        }

        $driver = $this->courier->driver();
        try {
            $responses = $driver->bookBulk($requests);
        } catch (\Throwable $e) {
            foreach ($orders->keys() as $no) {
                $result['failed'][$no] = $e->getMessage();
            }

            return $result;
        }

        foreach ($responses as $invoice => $r) {
            $order = $orders[$invoice] ?? null;
            if (! $order) {
                continue;
            }
            if (! $r->ok || ! $r->consignmentId) {
                $result['failed'][$invoice] = $r->error ?: __('Courier did not return a consignment ID.');

                continue;
            }

            try {
                DB::transaction(function () use ($order, $r, $by, $driver) {
                    DB::table('shipments')->where('order_id', $order->id)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
                    $shipmentId = DB::table('shipments')->insertGetId([
                        'order_id' => $order->id, 'courier' => $driver->name(), 'consignment_id' => $r->consignmentId,
                        'tracking_code' => $r->trackingCode, 'cod_amount' => $order->cod_amount, 'courier_status' => $r->status,
                        'booked_by' => $by?->id, 'booked_at' => now(), 'is_active' => true, 'raw_booking' => json_encode($r->raw),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $order->forceFill(['active_shipment_id' => $shipmentId, 'pickup_date' => $order->pickup_date ?? now()->toDateString(),
                        'booking_state' => 'none', 'booking_error' => null, 'packing_sent_at' => now()])->save();
                    $this->issueLabel($order, $shipmentId, $by, null);
                    $this->orders->note($order, 'courier', __('Booked with :c · CN :cn · COD ৳:cod', ['c' => ucfirst($driver->name()), 'cn' => $r->consignmentId, 'cod' => $order->cod_amount]), $by);
                    $this->machine->transition($order, 'ready_for_packaging', $by, 'system', null, 'CN '.$r->consignmentId);
                });
                $result['booked'][] = $invoice;
            } catch (\Throwable $e) {
                $result['failed'][$invoice] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * A new label for the order's current version. Older labels are voided,
     * so an out-of-date parcel can never be scanned through.
     */
    public function issueLabel(Order $order, int $shipmentId, ?User $by, ?string $voidReason): string
    {
        DB::table('shipment_labels')->where('order_id', $order->id)->whereNull('voided_at')
            ->update(['voided_at' => now(), 'void_reason' => $voidReason ?? __('Replaced'), 'updated_at' => now()]);

        $barcode = $order->order_no.'-'.$order->current_version;
        $n = 1;
        while (DB::table('shipment_labels')->where('barcode', $barcode)->exists()) {
            $barcode = $order->order_no.'-'.$order->current_version.'-'.(++$n);
        }

        DB::table('shipment_labels')->insert([
            'shipment_id' => $shipmentId, 'order_id' => $order->id, 'order_version' => $order->current_version,
            'barcode' => $barcode, 'cod_on_label' => $order->cod_amount, 'printed_by' => $by?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order->forceFill(['label_version' => $order->current_version])->save();

        return $barcode;
    }
}
