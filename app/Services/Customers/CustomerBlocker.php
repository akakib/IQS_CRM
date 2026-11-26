<?php

namespace App\Services\Customers;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\User;
use App\Services\Catalog\Store\WooApi;
use App\Services\Orders\OrderService;
use App\Services\Orders\OrderStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Block from an order (owner, managers): the customer is Blocked here (new orders stop for a person, the
 * "Blocked customer" check), the order can be cancelled in the same step, and on a website order the
 * website's Fake Order Guard blocks the phone / device / IP too, so the next checkout makes no order.
 * The website part is best effort: when it fails, the block here still stands and the note says why.
 */
class CustomerBlocker
{
    /** Why someone is blocked (picked in the Block popup). */
    public const REASONS = ['Fake or prank order', 'Returned again and again', 'Abusive customer', 'Wrong or fake number'];

    public function __construct(private OrderService $orders, private WooApi $website) {}

    /**
     * @param  array{reason: string, types?: list<string>, days?: int, cancel?: bool, cancel_reason_id?: int|null, note?: string|null}  $data
     * @return string what happened, for the page's message
     */
    public function block(Order $order, User $by, array $data): string
    {
        $customer = $order->customer;
        if (! $customer) {
            throw ValidationException::withMessages(['block' => __('This order has no customer to block.')]);
        }
        $reason = trim($data['reason'].(filled($data['note'] ?? null) ? ': '.$data['note'] : ''));

        DB::transaction(function () use ($order, $by, $data, $customer, $reason) {
            // Cancel first: if the order cannot be cancelled (a parcel the courier already has), nothing is blocked either.
            if (! empty($data['cancel']) && ! OrderStatus::map()[$order->status_id]['final']) {
                app(OrderStateMachine::class)->transition($order->fresh(), 'cancelled', $by, 'user', $data['cancel_reason_id'] ?? null, __('Blocked: :r', ['r' => $reason]));
            }
            $customer->forceFill(['risk_level' => 'blocked', 'blocked_reason' => mb_substr($reason, 0, 255)])->save();
        });

        $website = $this->onWebsite($order, 'block', [
            'types' => array_values(array_intersect($data['types'] ?? ['phone', 'device'], ['phone', 'device', 'ip'])),
            'days' => in_array((int) ($data['days'] ?? 30), [0, 7, 30], true) ? (int) $data['days'] : 30,
            'reason' => $reason, 'by' => $by->name,
        ]);
        $this->orders->note($order, 'system', __('Customer blocked by :n: :r.', ['n' => $by->name, 'r' => $reason]).($website ? ' '.$website : ''), $by);

        return __(':no: customer blocked.', ['no' => $order->order_no]).($website ? ' '.$website : '');
    }

    public function unblock(Order $order, User $by): string
    {
        $customer = $order->customer;
        if (! $customer) {
            throw ValidationException::withMessages(['block' => __('This order has no customer to block.')]);
        }
        $customer->forceFill(['risk_level' => 'normal', 'blocked_reason' => null])->save();
        $website = $this->onWebsite($order, 'unblock', ['by' => $by->name]);
        $this->orders->note($order, 'system', __('Customer unblocked by :n.', ['n' => $by->name]).($website ? ' '.$website : ''), $by);

        return __(':no: customer unblocked.', ['no' => $order->order_no]).($website ? ' '.$website : '');
    }

    /** The website's Fake Order Guard, for website orders: what it said, in a few words (null: not a website order). */
    private function onWebsite(Order $order, string $what, array $data): ?string
    {
        if ($order->channel !== 'web' || ! $order->external_ref || ! $this->website->configured()) {
            return null;
        }
        try {
            $r = $this->website->post('t5fob/v1/'.$what, ['order_id' => (int) $order->external_ref] + $data)->json();

            return $what === 'block'
                ? (empty($r['blocked']) ? __('Website: nothing new to block (already blocked).') : __('Website blocked: :w.', ['w' => implode(', ', $r['blocked'])]))
                : __('Website unblocked (:n).', ['n' => (int) ($r['removed'] ?? 0)]);
        } catch (\Throwable $e) {
            report($e);

            return __('Website could not be reached, block it there by hand: :e', ['e' => mb_substr($e->getMessage(), 0, 120)]);
        }
    }
}
