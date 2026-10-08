<?php

namespace App\Services\Packaging;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Catalog\StockService;
use App\Services\NotificationService;
use App\Services\Orders\DeskService;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns at the shop. A parcel Steadfast returned (or partly delivered) waits
 * on "To receive" until someone scans it and marks each item: Good (back on
 * the shelf, the count goes up), Damaged or Missing (managers are told).
 * Waiting longer than the setting's days shows red.
 */
class ReturnService
{
    public const STATES = ['returned', 'partial_delivered'];

    public const CONDITIONS = ['good' => 'Good', 'damaged' => 'Damaged', 'missing' => 'Not in the parcel'];

    public function __construct(private OrderService $orders, private StockService $stock) {}

    /** A scanned label, CN or order number: the returned order it belongs to. */
    public function find(string $code): ?Order
    {
        $code = trim($code);
        $orderId = DB::table('shipment_labels')->where('barcode', $code)->value('order_id')
            ?? DB::table('shipments')->where('consignment_id', $code)->value('order_id')
            ?? DB::table('orders')->where('order_no', strtoupper(preg_replace('/-\d+$/', '', $code)))->value('id');

        return $orderId ? Order::find($orderId) : null;
    }

    /** Mark the parcel received, item by item: [order_item_id => good|damaged|missing]. */
    public function receive(Order $order, array $conditions, User $by): array
    {
        return DB::transaction(function () use ($order, $conditions, $by) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array(OrderStatus::map()[$order->status_id]['key'], self::STATES, true)) {
                throw ValidationException::withMessages(['order' => __(':no was not returned.', ['no' => $order->order_no])]);
            }
            if ($order->return_received_at) {
                throw ValidationException::withMessages(['order' => __(':no was already received.', ['no' => $order->order_no])]);
            }
            $items = DB::table('order_items')->where('order_id', $order->id)->get(['id', 'variant_id', 'qty', 'name_snapshot']);
            if ($items->pluck('id')->diff(array_keys($conditions))->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => __('Mark every item first.')]);
            }
            $count = ['good' => 0, 'damaged' => 0, 'missing' => 0];
            $good = [];
            foreach ($items as $item) {
                $state = $conditions[$item->id];
                abort_unless(isset(self::CONDITIONS[$state]), 422);
                DB::table('return_items')->insert([
                    'order_id' => $order->id, 'order_item_id' => $item->id, 'variant_id' => $item->variant_id,
                    'qty' => $item->qty, 'condition' => $state, 'user_id' => $by->id, 'created_at' => now(),
                ]);
                $count[$state]++;
                if ($state === 'good' && $item->variant_id) {
                    $good[$item->variant_id] = ($good[$item->variant_id] ?? 0) + (int) round((float) $item->qty);
                }
            }
            $order->forceFill(['return_received_at' => now(), 'return_received_by' => $by->id])->save();
            $this->stock->returnGood($order, $good, $by);

            $this->orders->note($order, 'system', __('Return received at the shop by :n: :g good, :d damaged, :m not in the parcel.', [
                'n' => $by->name, 'g' => $count['good'], 'd' => $count['damaged'], 'm' => $count['missing'],
            ]), $by);
            if ($count['damaged'] || $count['missing']) {
                app(NotificationService::class)->send('return_problem', __('Return :no: items damaged or missing', ['no' => $order->order_no]),
                    __(':d damaged, :m not in the parcel. Received by :n.', ['d' => $count['damaged'], 'm' => $count['missing'], 'n' => $by->name]), [
                        'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'user_ids' => app(DeskService::class)->managerIds(),
                    ]);
            }

            return $count;
        });
    }

    /** When it was returned (the courier said so). */
    public function returnedAt(array $orderIds): \Illuminate\Support\Collection
    {
        return DB::table('order_events')->whereIn('order_id', $orderIds)->whereIn('to_status_id', OrderStatus::idsFor(self::STATES))
            ->groupBy('order_id')->selectRaw('order_id, MAX(created_at) as at')->pluck('at', 'order_id');
    }
}
