<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/** Order money is always derived: items + delivery rule + verified payments. */
class OrderCalculator
{
    public function __construct(private DeliveryCharges $charges) {}

    /**
     * @param  list<array{qty: float|string, unit_price: float|string, line_discount?: float|string, weight_g?: int}>  $items
     * @return array{subtotal: float, discount_total: float, delivery_charge: float, grand_total: float, total_weight_g: int}
     */
    public function totals(array $items, ?int $zoneId, float $orderDiscount = 0, ?float $deliveryOverride = null): array
    {
        $subtotal = 0.0;
        $lineDiscounts = 0.0;
        $weight = 0;

        foreach ($items as $item) {
            $gross = round((float) $item['qty'] * (float) $item['unit_price'], 2);
            $subtotal += $gross;
            $lineDiscounts += (float) ($item['line_discount'] ?? 0);
            $weight += (int) ($item['weight_g'] ?? 0);
        }

        $discount = round($lineDiscounts + $orderDiscount, 2);
        $afterDiscount = max(0, $subtotal - $discount);
        $delivery = $deliveryOverride ?? $this->charges->for($zoneId, $weight, $afterDiscount);

        return [
            'subtotal' => round($subtotal, 2),
            'discount_total' => $discount,
            'delivery_charge' => round($delivery, 2),
            'grand_total' => round($afterDiscount + $delivery, 2),
            'total_weight_g' => $weight,
        ];
    }

    /** COD and payment status from verified payments. Saves the order. */
    public function applyPayments(Order $order): void
    {
        // Verified money, plus a small advance that counts before its check (see payments.trust_up_to).
        $advance = (float) DB::table('order_payments')->where('order_id', $order->id)->whereIn('payment_type', ['advance', 'adjustment'])
            ->where(fn ($q) => $q->where('status', 'verified')->orWhere(fn ($q) => $q->where('status', 'pending_verification')->where('counts_now', true)))->sum('amount');
        $refunded = (float) DB::table('order_payments')->where('order_id', $order->id)
            ->where('status', 'verified')->where('payment_type', 'refund')->sum('amount');

        $grand = (float) $order->grand_total;
        // Cancelled or returned: nothing was sold, so all that was paid is owed back.
        $closed = in_array(\App\Models\OrderStatus::map()[$order->status_id]['key'] ?? null, ['cancelled', 'returned'], true);
        $order->advance_verified = round($advance, 2);
        $order->cod_amount = round(max(0, $grand - $advance), 2);
        $order->refund_due = round(max(0, $advance - ($closed ? 0 : $grand) - $refunded), 2);
        $order->payment_status = match (true) {
            $order->refund_due > 0 => 'refund_due',
            $closed && $refunded > 0 => 'refunded',
            $advance > 0 && $advance >= $grand => 'fully_prepaid',
            $advance > 0 => 'partial_advance',
            default => $order->payment_status === 'cod_collected' ? 'cod_collected' : 'unpaid',
        };
        $order->save();
    }
}
