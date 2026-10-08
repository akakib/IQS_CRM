<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money a customer paid on an order that did not go through (Cancelled or
 * Returned). It shows on Payments > Refunds until someone either gives it
 * back (method, TrxID) or keeps it as the customer's credit, which is used by
 * itself on their next order. Each is a "refund" payment on the order, so the
 * order's own numbers stay right.
 */
class RefundService
{
    public const CLOSED = ['cancelled', 'returned'];

    public function __construct(private OrderService $orders, private OrderCalculator $calculator) {}

    /** The order was cancelled or returned: what was paid is now owed back. */
    public function closed(Order $order): void
    {
        $this->calculator->applyPayments($order->refresh());
        if ((float) $order->refund_due > 0) {
            $this->orders->note($order, 'payment', __('৳:a paid in advance is owed back: give it back or keep it as credit (Payments, Refunds).', ['a' => number_format((float) $order->refund_due)]), null);
        }
    }

    /** Given back to the customer. */
    public function refund(Order $order, float $amount, int $methodId, ?string $trx, ?string $note, User $by): void
    {
        DB::transaction(function () use ($order, $amount, $methodId, $trx, $note, $by) {
            $order = $this->locked($order, $amount);
            DB::table('order_payments')->insert([
                'order_id' => $order->id, 'payment_type' => 'refund', 'method_id' => $methodId, 'amount' => round($amount, 2),
                'transaction_id' => $trx ? trim($trx) : null, 'status' => 'verified', 'verified_by' => $by->id, 'verified_at' => now(),
                'received_at' => now(), 'note' => $note, 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->calculator->applyPayments($order);
            $this->orders->note($order, 'payment', __('৳:a given back to the customer (:m:t) by :n.', [
                'a' => number_format($amount), 'm' => DB::table('payment_methods')->where('id', $methodId)->value('name'), 't' => $trx ? ', '.$trx : '', 'n' => $by->name,
            ]).($note ? ' · '.$note : ''), $by);
        });
    }

    /** Kept for the customer's next order. */
    public function keepAsCredit(Order $order, float $amount, User $by): void
    {
        DB::transaction(function () use ($order, $amount, $by) {
            $order = $this->locked($order, $amount);
            abort_unless($order->customer_id, 422);
            DB::table('order_payments')->insert([
                'order_id' => $order->id, 'payment_type' => 'refund', 'method_id' => $this->creditMethodId(), 'amount' => round($amount, 2),
                'status' => 'verified', 'verified_by' => $by->id, 'verified_at' => now(), 'received_at' => now(),
                'note' => __('Kept as credit'), 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $balance = $this->moveCredit($order->customer_id, $order->id, $amount, __('Kept from :no', ['no' => $order->order_no]), $by);
            $this->calculator->applyPayments($order);
            $this->orders->note($order, 'payment', __('৳:a kept as the customer\'s credit for the next order by :n (credit now ৳:b).', ['a' => number_format($amount), 'n' => $by->name, 'b' => number_format($balance)]), $by);
        });
    }

    /** A new order for a customer with credit: it is used by itself, up to the order's total. */
    public function useCredit(Order $order, ?User $by): void
    {
        if (! $order->customer_id || (float) $order->grand_total <= 0) {
            return;
        }
        DB::transaction(function () use ($order, $by) {
            $credit = (float) DB::table('customers')->where('id', $order->customer_id)->lockForUpdate()->value('credit_balance');
            $use = round(min($credit, (float) $order->grand_total), 2);
            if ($use <= 0) {
                return;
            }
            DB::table('order_payments')->insert([
                'order_id' => $order->id, 'payment_type' => 'advance', 'method_id' => $this->creditMethodId(), 'amount' => $use,
                'status' => 'verified', 'verified_at' => now(), 'received_at' => now(), 'note' => __('Customer credit'),
                'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $balance = $this->moveCredit($order->customer_id, $order->id, -$use, __('Used on :no', ['no' => $order->order_no]), $by);
            $this->calculator->applyPayments($order->refresh());
            $this->orders->note($order, 'payment', __('৳:a of the customer\'s credit used on this order (credit left ৳:b).', ['a' => number_format($use), 'b' => number_format($balance)]), $by);
        });
    }

    public function creditMethodId(): int
    {
        return (int) DB::table('payment_methods')->where('system_key', 'credit')->value('id');
    }

    private function locked(Order $order, float $amount): Order
    {
        $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
        if (! in_array(OrderStatus::map()[$order->status_id]['key'], self::CLOSED, true)) {
            throw ValidationException::withMessages(['amount' => __('Only a cancelled or returned order is refunded.')]);
        }
        if ($amount <= 0 || round($amount, 2) > round((float) $order->refund_due, 2)) {
            throw ValidationException::withMessages(['amount' => __('Owed on this order: ৳:a.', ['a' => number_format((float) $order->refund_due, 2)])]);
        }

        return $order;
    }

    private function moveCredit(int $customerId, int $orderId, float $amount, string $note, ?User $by): float
    {
        DB::table('customers')->where('id', $customerId)->update(['credit_balance' => DB::raw('credit_balance + '.round($amount, 2))]);
        $balance = (float) DB::table('customers')->where('id', $customerId)->value('credit_balance');
        DB::table('customer_credits')->insert([
            'customer_id' => $customerId, 'order_id' => $orderId, 'amount' => round($amount, 2), 'balance_after' => $balance,
            'note' => $note, 'user_id' => $by?->id, 'created_at' => now(),
        ]);

        return $balance;
    }
}
