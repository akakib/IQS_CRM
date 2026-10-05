<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Services\NotificationService;
use App\Support\Phone;
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

        try {
            $existing = Order::where('channel', 'web')->where('external_ref', (string) $p['id'])->first();
            if ($existing) {
                $this->finish($inboxId, 'processed', null, $existing->id);

                return $existing;
            }

            $order = DB::transaction(fn () => $this->create($p));
            $this->finish($inboxId, 'processed', null, $order->id);
            app(VerificationEngine::class)->run($order);

            return $order;
        } catch (Throwable $e) {
            $this->finish($inboxId, 'failed', $e->getMessage());
            $this->notifications->send('sync_failed', __('Website order #:id could not be imported', ['id' => $p['id'] ?? '?']), mb_substr($e->getMessage(), 0, 400), [
                'link' => route('settings.integrations'), 'group_key' => 'woo_intake_failed',
            ]);

            return null;
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
        $gateway = strtolower((string) ($p['payment_method'] ?? '').' '.($p['payment_method_title'] ?? ''));
        if ($gateway === ' ' || str_contains($gateway, 'cod') || str_contains($gateway, 'cash on delivery')) {
            return;
        }
        $methodKey = collect(['bkash', 'nagad', 'rocket', 'bank', 'card'])->first(fn ($k) => str_contains($gateway, $k)) ?? 'card';
        $methodId = DB::table('payment_methods')->where('system_key', $methodKey)->value('id');
        $trx = trim((string) ($p['transaction_id'] ?? '')) ?: (string) ($meta->first(fn ($v, $k) => is_scalar($v) && preg_match('/trx|transaction/i', (string) $k)) ?? '');
        $paid = filled($p['date_paid'] ?? null) || filled($p['date_paid_gmt'] ?? null);
        if (! $methodId || (! $paid && $trx === '')) {
            return;
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
