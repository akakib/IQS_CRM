<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Refund;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Orders\OrderCalculator;
use App\Services\Orders\OrderService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refunds need two people: one requests, another approves. Money leaves only
 * at "paid" (with the bKash/Nagad transaction ID), which writes a verified
 * `refund` row into order_payments so the order's refund_due and payment
 * status follow automatically. Nothing is deleted: a wrong request is rejected.
 */
class RefundService
{
    public function __construct(
        private OrderService $orders,
        private OrderCalculator $calculator,
        private NotificationService $notifications,
    ) {}

    /** What the customer actually paid us so far, minus refunds already paid or approved. */
    public function maxRefundable(Order $order): float
    {
        $paid = (float) $order->advance_verified;
        if ($order->payment_status === 'cod_collected' || in_array(OrderStatus::map()[$order->status_id]['key'] ?? null, ['delivered', 'partial_delivered'], true)) {
            $paid += (float) DB::table('shipments')->where('id', $order->active_shipment_id)->value('collected_amount') ?: (float) $order->cod_amount;
        }
        $committed = (float) DB::table('refunds')->where('order_id', $order->id)->whereIn('status', ['approved', 'paid'])->sum('amount');

        return round(max(0, $paid - $committed), 2);
    }

    /**
     * @param  array{amount: float|string, method_id: int, recipient_number?: string|null, reason_id?: int|null, note?: string|null, complaint_id?: int|null}  $data
     */
    public function request(Order $order, array $data, User $by): Refund
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('Amount must be more than zero.')]);
        }
        $max = $this->maxRefundable($order);
        if ($amount > $max) {
            throw ValidationException::withMessages(['amount' => __('The customer paid ৳:m on this order (after refunds already approved). Refund up to that.', ['m' => number_format($max, 2)])]);
        }
        $method = DB::table('payment_methods')->where('id', $data['method_id'] ?? 0)->where('is_active', true)->first();
        if (! $method) {
            throw ValidationException::withMessages(['method_id' => __('Choose how to send the money.')]);
        }
        $recipient = Phone::normalize($data['recipient_number'] ?? null);
        if ($method->requires_trx_id && ! $recipient) {
            throw ValidationException::withMessages(['recipient_number' => __('Give the :m number to send to.', ['m' => $method->name])]);
        }
        $complaint = ! empty($data['complaint_id']) ? Complaint::find($data['complaint_id']) : null;
        if ($complaint && $complaint->order_id !== $order->id) {
            throw ValidationException::withMessages(['complaint_id' => __('That complaint belongs to another order.')]);
        }

        return DB::transaction(function () use ($order, $data, $by, $amount, $method, $recipient, $complaint) {
            $refund = Refund::create([
                'order_id' => $order->id, 'complaint_id' => $complaint?->id, 'amount' => $amount, 'method_id' => $method->id,
                'recipient_number' => $recipient, 'reason_id' => $data['reason_id'] ?? null, 'note' => $data['note'] ?? null,
                'status' => 'pending', 'requested_by' => $by->id,
            ]);
            $line = __('Refund ৳:a via :m requested', ['a' => number_format($amount, 2), 'm' => $method->name]);
            $this->orders->note($order, 'payment', $line.' ('.__('waiting for approval').').', $by, ['refund_id' => $refund->id]);
            if ($complaint) {
                app(ComplaintService::class)->event($complaint, 'refund_requested', $line, $by);
            }
            $this->notifications->send('refund_pending_approval', __('Refund ৳:a on :o', ['a' => number_format($amount), 'o' => $order->order_no]),
                $data['note'] ?? null, ['link' => route('refunds.index', ['tab' => 'pending']), 'subject' => ['refund', $refund->id], 'group_key' => 'refund:'.$refund->id]);

            return $refund;
        });
    }

    /** Approve or reject. Never by the person who asked for it. */
    public function decide(Refund $refund, bool $approve, ?string $note, User $by): void
    {
        if ($refund->status !== 'pending') {
            throw ValidationException::withMessages(['status' => __('This refund was already decided.')]);
        }
        if ($refund->requested_by === $by->id && ! $by->isOwner()) {
            throw ValidationException::withMessages(['status' => __('A refund must be approved by someone other than the person who requested it.')]);
        }

        DB::transaction(function () use ($refund, $approve, $note, $by) {
            $refund->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);
            $order = $refund->order;
            $word = $approve ? __('approved') : __('rejected');
            $this->orders->note($order, 'payment', __('Refund ৳:a :w by :n:note', ['a' => number_format((float) $refund->amount, 2), 'w' => $word, 'n' => $by->name, 'note' => $note ? ' · '.$note : '']), $by, ['refund_id' => $refund->id]);
            if ($refund->complaint_id && ($complaint = Complaint::find($refund->complaint_id))) {
                app(ComplaintService::class)->event($complaint, $approve ? 'refund_approved' : 'refund_rejected', __('Refund ৳:a :w', ['a' => number_format((float) $refund->amount, 2), 'w' => $word]).($note ? ' · '.$note : ''), $by);
            }
            $this->notifications->markActed('refund', $refund->id);
            if ($refund->requested_by) {
                $this->notifications->send('refund_decided', __('Refund ৳:a on :o :w', ['a' => number_format((float) $refund->amount), 'o' => $order->order_no, 'w' => $word]), $note, [
                    'link' => route('orders.show', $order), 'subject' => ['refund', $refund->id], 'user_ids' => [$refund->requested_by],
                ]);
            }
        });
    }

    /** Money sent: record the transaction and write the ledger row on the order. */
    public function markPaid(Refund $refund, ?string $transactionId, User $by): void
    {
        if ($refund->status !== 'approved') {
            throw ValidationException::withMessages(['status' => __('Only an approved refund can be marked as paid.')]);
        }
        $method = DB::table('payment_methods')->where('id', $refund->method_id)->first();
        $trx = trim((string) $transactionId) ?: null;
        if ($method->requires_trx_id && ! $trx) {
            throw ValidationException::withMessages(['transaction_id' => __('Transaction ID is required for :m.', ['m' => $method->name])]);
        }
        if ($trx && DB::table('order_payments')->where('method_id', $method->id)->where('transaction_id', $trx)->exists()) {
            throw ValidationException::withMessages(['transaction_id' => __('This transaction ID was already used.')]);
        }

        DB::transaction(function () use ($refund, $trx, $by, $method) {
            $order = $refund->order;
            $paymentId = DB::table('order_payments')->insertGetId([
                'order_id' => $order->id, 'payment_type' => 'refund', 'method_id' => $method->id, 'amount' => $refund->amount,
                'transaction_id' => $trx, 'sender_number' => $refund->recipient_number, 'status' => 'verified',
                'verified_by' => $by->id, 'verified_at' => now(), 'received_at' => now(), 'note' => __('Refund #:id', ['id' => $refund->id]),
                'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $refund->update(['status' => 'paid', 'paid_by' => $by->id, 'paid_at' => now(), 'transaction_id' => $trx, 'payment_id' => $paymentId]);
            $this->calculator->applyPayments($order->refresh());
            // Everything the customer prepaid went back: say so instead of "fully prepaid".
            $refunded = (float) DB::table('order_payments')->where('order_id', $order->id)->where('payment_type', 'refund')->where('status', 'verified')->sum('amount');
            if ((float) $order->advance_verified > 0 && (float) $order->refund_due <= 0 && $refunded >= (float) $order->advance_verified) {
                $order->forceFill(['payment_status' => 'refunded'])->save();
            }
            $this->orders->note($order, 'payment', __('Refund ৳:a paid via :m (:t).', ['a' => number_format((float) $refund->amount, 2), 'm' => $method->name, 't' => $trx ?? '-']), $by, ['refund_id' => $refund->id]);
            if ($refund->complaint_id && ($complaint = Complaint::find($refund->complaint_id))) {
                app(ComplaintService::class)->event($complaint, 'refund_paid', __('Refund ৳:a paid (:t)', ['a' => number_format((float) $refund->amount, 2), 't' => $trx ?? '-']), $by);
            }
            if ($refund->requested_by && $refund->requested_by !== $by->id) {
                $this->notifications->send('refund_decided', __('Refund ৳:a on :o paid', ['a' => number_format((float) $refund->amount), 'o' => $order->order_no]), $trx, [
                    'link' => route('orders.show', $order), 'subject' => ['refund', $refund->id], 'user_ids' => [$refund->requested_by],
                ]);
            }
        });
    }
}
