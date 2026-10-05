<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Services\NotificationService;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a stored WooCommerce order webhook (integration_inbox row) into an
 * IQS order. Idempotent: an inbox row is processed once, and a Woo order id
 * already imported is never created twice. Prices, discounts and delivery
 * charge are what the customer saw on the website.
 */
class WooOrderIntake
{
    public function __construct(
        private OrderService $orders,
        private OrderStateMachine $machine,
        private NotificationService $notifications,
    ) {}

    public function process(int $inboxId): ?Order
    {
        $inbox = DB::table('integration_inbox')->where('id', $inboxId)->first();
        if (! $inbox || $inbox->status === 'processed') {
            return null;
        }
        $p = json_decode($inbox->payload, true);

        // Webhooks of one order are handled one at a time, in arrival order, whatever Woo sends in parallel.
        return Cache::lock('woo:order:'.$p['id'], 30)->block(15, function () use ($inboxId, $p) {
            try {
                $existing = Order::where('channel', 'web')->where('external_ref', (string) $p['id'])->first();
                if ($existing) {
                    $this->update($existing, $p);
                    $this->finish($inboxId, 'processed', null, $existing->id);

                    return $existing;
                }
                // Only an order the website considers placed. Pending/failed = the customer never paid at the
                // gateway (an abandoned checkout, not an order); it is created if a later webhook says so.
                $status = strtolower((string) ($p['status'] ?? ''));
                if (! in_array($status, self::PLACED, true)) {
                    $this->finish($inboxId, 'ignored', "website status '{$status}'");

                    return null;
                }

                $order = DB::transaction(fn () => $this->create($p));
                $this->finish($inboxId, 'processed', null, $order->id);
                app(VerificationEngine::class)->run($order);

                return $order;
            } catch (Throwable $e) {
                $this->failed($inboxId, $p, $e);

                return null;
            }
        });
    }

    /** Website statuses that mean "this is an order" (the rest: pending, failed, cancelled, checkout-draft, trash). */
    private const PLACED = ['processing', 'on-hold', 'completed'];

    private function failed(int $inboxId, array $p, Throwable $e): void
    {
        try {
            $this->finish($inboxId, 'failed', $e->getMessage());
            $this->notifications->send('sync_failed', __('Website order #:id could not be imported', ['id' => $p['id'] ?? '?']), mb_substr($e->getMessage(), 0, 400), [
                'link' => route('settings.integrations'), 'group_key' => 'woo_intake_failed',
            ]);
        } catch (Throwable) {
            // telling failed too: the inbox row still says failed
        }
    }

    private function create(array $p): Order
    {
        $billing = $p['billing'] ?? [];
        $shipping = array_filter($p['shipping'] ?? []);
        $phone = Phone::normalize($billing['phone'] ?? null) ?? Phone::normalize($shipping['phone'] ?? null);
        if (! $phone) {
            throw new \RuntimeException('No valid Bangladesh phone number on the website order ('.($billing['phone'] ?? 'empty').').');
        }

        $addr = $shipping['address_1'] ?? null ? $shipping : $billing;
        $line = trim(implode(', ', array_filter([$addr['address_1'] ?? null, $addr['address_2'] ?? null])));
        $city = trim((string) ($addr['city'] ?? ''));
        $state = trim((string) ($addr['state'] ?? ''));
        $zoneKey = str_contains(strtolower($city.' '.$state), 'dhaka') ? 'inside_dhaka' : 'outside_dhaka';

        [$items, $outOfStock] = $this->items($p['line_items'] ?? []);
        $meta = collect($p['meta_data'] ?? [])->pluck('value', 'key');

        $order = $this->orders->create([
            'channel' => 'web',
            'external_ref' => (string) $p['id'],
            'phone' => $phone,
            'name' => trim(($addr['first_name'] ?? $billing['first_name'] ?? '').' '.($addr['last_name'] ?? $billing['last_name'] ?? '')) ?: $phone,
            'address_line' => $line ?: ($city ?: 'Address not given'),
            'district' => $state ?: null,
            'thana' => $city ?: null,
            'zone_id' => DB::table('delivery_zones')->where('system_key', $zoneKey)->value('id'),
            'items' => $items,
            'order_discount' => 0,
            'delivery_charge' => (float) ($p['shipping_total'] ?? 0),
            'customer_note' => ($p['customer_note'] ?? '') ?: null,
            'fbp' => $meta['_fbp'] ?? null,
            'fbc' => $meta['_fbc'] ?? null,
            'utm' => array_filter([
                'source' => $meta['_wc_order_attribution_utm_source'] ?? null,
                'medium' => $meta['_wc_order_attribution_utm_medium'] ?? null,
                'campaign' => $meta['_wc_order_attribution_utm_campaign'] ?? null,
                'source_type' => $meta['_wc_order_attribution_source_type'] ?? null,
            ]) ?: null,
            'source_campaign' => $meta['_wc_order_attribution_utm_campaign'] ?? null,
        ], null, 'webhook');

        $this->orders->note($order, 'system', __('Imported from website order #:n (:m).', ['n' => $p['number'] ?? $p['id'], 'm' => $p['payment_method_title'] ?? $p['payment_method'] ?? 'COD']), null);
        $this->payment($order, $p, $meta);
        $order->forceFill(['external_status' => strtolower((string) ($p['status'] ?? '')), 'external_paid_at' => $this->paidAt($p), 'external_total' => (float) ($p['total'] ?? 0)])->save();

        if ((float) ($p['total'] ?? 0) && abs((float) $p['total'] - (float) $order->grand_total) > 0.5) {
            $this->orders->note($order, 'system', __('Website total was ৳:w, IQS computed ৳:i. Please check.', ['w' => $p['total'], 'i' => $order->grand_total]), null);
        }

        // Sync delay: an item that is out of stock here goes straight to Hold.
        if ($outOfStock) {
            $reason = DB::table('status_reasons')->where('reason_type', 'hold')->where('system_key', 'stock_out')->value('id');
            $this->machine->transition($order, 'hold', null, 'system', $reason, __('Out of stock: :i', ['i' => implode(', ', $outOfStock)]));
        }

        return $order;
    }

    /** @return array{0: list<array>, 1: list<string>} items and names of out-of-stock ones */
    /**
     * Money already paid on the website. Paid through a gateway (WooCommerce
     * says date_paid): verified. Only a TrxID typed by the customer (manual
     * bKash, order on hold): to check on the Payments page. Cash on delivery: none.
     */
    private function payment(Order $order, array $p, \Illuminate\Support\Collection $meta): void
    {
        $gateway = $this->gateway($p);
        if ($gateway === ' ' || str_contains($gateway, 'cod') || str_contains($gateway, 'cash on delivery')) {
            return;
        }
        $methodKey = collect(['bkash', 'nagad', 'rocket', 'bank', 'card'])->first(fn ($k) => str_contains($gateway, $k)) ?? 'card';
        $methodId = DB::table('payment_methods')->where('system_key', $methodKey)->value('id');
        $trx = trim((string) ($p['transaction_id'] ?? '')) ?: (string) ($meta->first(fn ($v, $k) => is_scalar($v) && preg_match('/trx|transaction/i', (string) $k)) ?? '');
        // Verified only when a gateway that confirms money itself says paid. A manual method (send money,
        // TrxID typed by the customer) is checked in IQS, whatever status the website is given later.
        $paid = $this->paidAt($p) !== null && $this->onlineGateway($gateway);
        if (! $methodId || (! $paid && $trx === '')) {
            return;
        }
        $total = (float) ($p['total'] ?? 0);
        if ($paid && abs($total - (float) $order->grand_total) > 1) {
            $this->orders->note($order, 'system', __('Website paid ৳:w but the order total here is ৳:t. Check the difference.', ['w' => number_format($total, 2), 't' => number_format((float) $order->grand_total, 2)]), null);
        }
        try {
            $this->orders->addPayment($order->refresh(), [
                'payment_type' => 'advance', 'method_id' => $methodId, 'amount' => (float) ($p['total'] ?? 0), 'transaction_id' => $trx ?: null,
                'status' => $paid ? 'verified' : null,
                'note' => $paid ? __('Paid on the website') : __('TrxID typed on the website; amount = website total, check it'),
            ], null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->orders->note($order, 'system', __('Website payment not added: :e', ['e' => collect($e->errors())->flatten()->first()]), null);
        }
    }

    private function gateway(array $p): string
    {
        return strtolower((string) ($p['payment_method'] ?? '').' '.($p['payment_method_title'] ?? ''));
    }

    private function onlineGateway(string $gateway): bool
    {
        foreach (array_filter(array_map('trim', explode(',', strtolower((string) settings('payments.online_gateways'))))) as $name) {
            if (str_contains($gateway, $name)) {
                return true;
            }
        }

        return false;
    }

    private function paidAt(array $p): ?\Illuminate\Support\Carbon
    {
        $raw = $p['date_paid_gmt'] ?? $p['date_paid'] ?? null;

        return filled($raw) ? \Illuminate\Support\Carbon::parse($raw) : null;
    }

    /**
     * A later webhook of an order IQS already has. Only real changes act, once:
     *  - became paid at an online gateway: a verified payment (the advance hold releases itself);
     *  - cancelled / refunded on the website: cancelled here only while nobody called and nothing is
     *    booked, otherwise the moderator is told and decides;
     *  - anything else (items, address edited on the website): IQS owns the order, a note only.
     */
    private function update(Order $order, array $p): void
    {
        $status = strtolower((string) ($p['status'] ?? ''));
        $paidAt = $this->paidAt($p);
        $total = (float) ($p['total'] ?? 0);
        $before = ['status' => $order->external_status, 'paid' => $order->external_paid_at, 'total' => $order->external_total];

        if ($paidAt && ! $before['paid'] && $this->onlineGateway($this->gateway($p))) {
            $this->payment($order->refresh(), $p, collect($p['meta_data'] ?? [])->pluck('value', 'key'));
            if (OrderStatus::map()[$order->fresh()->status_id]['key'] === 'new') {
                app(VerificationEngine::class)->run($order->fresh()); // the rules may now pass it
            }
        }

        if (in_array($status, ['cancelled', 'refunded', 'trash'], true) && $before['status'] !== $status) {
            $this->websiteCancelled($order->fresh(), $status);
        } elseif ($before['status'] !== null && $before['total'] !== null && abs($total - (float) $before['total']) > 0.01) {
            $this->orders->note($order, 'system', __('Edited on the website after import (total ৳:a, was ৳:b). This order is managed here; the website change was not applied.', ['a' => number_format($total, 2), 'b' => number_format((float) $before['total'], 2)]), null);
        }

        $order->forceFill(['external_status' => $status, 'external_paid_at' => $paidAt ?? $before['paid'], 'external_total' => $total])->save();
    }

    private function websiteCancelled(Order $order, string $status): void
    {
        $key = OrderStatus::map()[$order->status_id]['key'];
        $called = DB::table('order_notes')->where('order_id', $order->id)->where('note_type', 'call')->exists();
        $untouched = ! $called && ! $order->active_shipment_id && in_array($key, ['new', 'record_verified', 'no_answer', 'hold'], true);
        if ($untouched) {
            $reason = DB::table('status_reasons')->where('reason_type', 'cancel')->where('system_key', 'website_cancelled')->value('id');
            $this->machine->transition($order, 'cancelled', null, 'webhook', $reason, __('Cancelled on the website (:s)', ['s' => $status]));

            return;
        }
        $this->orders->note($order, 'system', __('The customer :s this order on the website. Already :k here: decide what to do.', ['s' => $status === 'refunded' ? 'refunded' : 'cancelled', 'k' => strtolower(OrderStatus::map()[$order->status_id]['name'])]), null);
        $this->notifications->send('sync_failed', __(':s on the website: :no', ['s' => ucfirst($status), 'no' => $order->order_no]),
            __('Already :k here. Cancel it (and the parcel at the courier) if the customer really does not want it.', ['k' => strtolower(OrderStatus::map()[$order->status_id]['name'])]), [
                'link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'priority' => 'urgent',
                'user_ids' => array_filter([$order->moderator_id]) ?: app(DeskService::class)->managerIds(),
            ]);
    }

    private function items(array $lines): array
    {
        if ($lines === []) {
            throw new \RuntimeException('The website order has no products.');
        }

        $items = [];
        $outOfStock = [];
        foreach ($lines as $l) {
            $variantId = DB::table('channel_product_links')->where('channel', 'woocommerce')
                ->where('external_product_id', (string) $l['product_id'])
                ->where(fn ($q) => ! empty($l['variation_id'])
                    ? $q->where('external_variant_id', (string) $l['variation_id'])
                    : $q->whereNull('external_variant_id'))
                ->value('variant_id')
                ?? (! empty($l['sku']) ? ProductVariant::where('sku', $l['sku'])->value('id') : null);

            if (! $variantId) {
                throw new \RuntimeException("Unknown product on the website order: {$l['name']} (SKU ".($l['sku'] ?: '-').'). Import products first.');
            }

            $qty = (float) $l['quantity'];
            $subtotal = (float) ($l['subtotal'] ?? $l['total']);
            $total = (float) $l['total'];
            $items[] = [
                'variant_id' => $variantId,
                'qty' => $qty,
                'unit_price' => $qty > 0 ? round($subtotal / $qty, 2) : 0,
                'line_discount' => round(max(0, $subtotal - $total), 2),
            ];

            if (ProductVariant::whereKey($variantId)->value('availability_status') === 'out_of_stock') {
                $outOfStock[] = $l['name'];
            }
        }

        return [$items, $outOfStock];
    }

    private function finish(int $inboxId, string $status, ?string $error, ?int $orderId = null): void
    {
        DB::table('integration_inbox')->where('id', $inboxId)->update([
            'status' => $status, 'error' => $error, 'order_id' => $orderId, 'processed_at' => now(),
        ]);
    }
}
