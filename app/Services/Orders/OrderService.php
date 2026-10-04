<?php

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Services\NotificationService;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creating orders, ownership (claim / reassign), notes and payments. */
class OrderService
{
    public function __construct(
        private OrderCalculator $calculator,
        private CustomerService $customers,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array{channel: string, phone: string, name: string, customer_address_id?: ?int, address_line?: ?string,
     *     district?: ?string, thana?: ?string, zone_id?: ?int, items: list<array{variant_id: int, qty: float, unit_price?: ?float, line_discount?: ?float}>,
     *     order_discount?: ?float, customer_note?: ?string, external_ref?: ?string, utm?: ?array, fbp?: ?string, fbc?: ?string,
     *     delivery_charge?: ?float, advance?: ?array, alt_phone?: ?string}  $data
     * @param  string  $source  user | webhook
     */
    public function create(array $data, ?User $by, string $source = 'user'): Order
    {
        $phone = Phone::normalize($data['phone'] ?? null)
            ?? throw ValidationException::withMessages(['phone' => __('Phone must be a Bangladesh mobile number like 01XXXXXXXXX.')]);

        return DB::transaction(function () use ($data, $by, $source, $phone) {
            $customer = $this->customers->findByPhone($phone)
                ?? $this->customers->save(new Customer, ['name' => $data['name'] ?: $phone, 'primary_phone' => $phone], [], [], $by?->id);

            $address = ! empty($data['customer_address_id'])
                ? $customer->addresses()->whereKey($data['customer_address_id'])->first()
                : null;
            if (! $address) {
                if (blank($data['address_line'] ?? null)) {
                    throw ValidationException::withMessages(['address_line' => __('Delivery address is required.')]);
                }
                $address = $customer->addresses()->create([
                    'address_line' => trim($data['address_line']),
                    'district' => $data['district'] ?? null,
                    'thana' => $data['thana'] ?? null,
                    'zone_id' => $data['zone_id'] ?? null,
                    'is_default' => ! $customer->addresses()->exists(),
                ]);
            }

            $items = $this->buildItems($data['items'] ?? [], $by, $source === 'webhook');
            $totals = $this->calculator->totals($items, $address->zone_id ?? ($data['zone_id'] ?? null), (float) ($data['order_discount'] ?? 0),
                isset($data['delivery_charge']) ? (float) $data['delivery_charge'] : null);

            if ($source === 'user' && $totals['discount_total'] > (float) settings('orders.discount_limit') && ! $by?->can('orders.approve')) {
                throw ValidationException::withMessages(['order_discount' => __('Discounts above ৳:n need a manager. Ask a manager to create or approve this order.', ['n' => settings('orders.discount_limit')])]);
            }

            $isChat = $data['channel'] !== 'web';
            $duplicate = DB::table('orders')->where('customer_id', $customer->id)
                ->whereNotIn('status_id', OrderStatus::idsFor(['cancelled', 'delivered', 'returned', 'partial_delivered']))
                ->where('created_at', '>=', now()->subHours((int) settings('orders.duplicate_window_hours')))->exists();

            $order = Order::create([
                'channel' => $data['channel'],
                'external_ref' => $data['external_ref'] ?? null,
                'customer_id' => $customer->id,
                'status_id' => OrderStatus::idFor('new'),
                'owner_id' => $isChat ? $by?->id : null,
                'created_by' => $by?->id,
                'ship_name' => trim($data['name'] ?: $customer->name),
                'ship_phone' => $phone,
                'ship_alt_phone' => Phone::normalize($data['alt_phone'] ?? null),
                'ship_address' => $address->address_line,
                'ship_district' => $address->district,
                'ship_thana' => $address->thana,
                'zone_id' => $address->zone_id,
                'customer_note' => $data['customer_note'] ?? null,
                'utm' => $data['utm'] ?? null,
                'fbp' => $data['fbp'] ?? null,
                'fbc' => $data['fbc'] ?? null,
                'source_campaign' => $data['source_campaign'] ?? null,
                'is_duplicate_flag' => $duplicate,
            ] + $totals + ['cod_amount' => $totals['grand_total']]);

            $order->forceFill(['order_no' => 'IQ'.(10000 + $order->id)])->save();
            $order->items()->createMany($items);
            $this->snapshot($order, 1, $by?->id);

            DB::table('order_events')->insert([
                'order_id' => $order->id, 'from_status_id' => null, 'to_status_id' => $order->status_id,
                'source' => $source === 'webhook' ? 'webhook' : 'user', 'user_id' => $by?->id, 'created_at' => now(),
            ]);
            $this->note($order, 'system', __('Order created via :ch:by.', ['ch' => $data['channel'], 'by' => $by ? ' '.__('by :n', ['n' => $by->name]) : '']), $by);
            if ($duplicate) {
                $this->note($order, 'system', __('Same customer already has an open order in the last :h hours. Check for a duplicate.', ['h' => settings('orders.duplicate_window_hours')]), null);
            }

            if ($isChat && $by) {
                $this->openAssignment($order, $by->id, 'created', null);
            }

            DB::table('customers')->where('id', $customer->id)->update([
                'orders_count' => DB::raw('orders_count + 1'),
                'first_order_at' => $customer->first_order_at ?? now(),
            ]);

            if (! empty($data['advance']['amount'])) {
                $this->addPayment($order, $data['advance'] + ['payment_type' => 'advance'], $by);
            }

            app(\App\Services\Tracking\TrackingService::class)->handle($order, 'order_created');

            if (! $order->owner_id) {
                $this->notifications->send('new_order', __('New order :no · ৳:t', ['no' => $order->order_no, 't' => number_format((float) $order->grand_total)]),
                    $order->ship_name, ['link' => route('orders.show', $order), 'subject' => ['order', $order->id], 'group_key' => 'new_order']);
            }

            return $order->refresh();
        });
    }

    /**
     * Take an unowned order. One person works on at most N orders at a time
     * (setting), and the order is theirs from then on; only an admin can move it.
     */
    public function claim(Order $order, User $user): Order
    {
        $working = DB::table('orders')->where('owner_id', $user->id)
            ->whereIn('status_id', OrderStatus::idsFor(['new', 'record_verified']))->count();
        if ($working >= (int) settings('orders.max_working_orders')) {
            throw ValidationException::withMessages(['order' => __('Finish your current order first (confirm, hold, no answer or cancel it).')]);
        }

        // Atomic: of two people pressing at once, exactly one wins.
        $won = DB::table('orders')->where('id', $order->id)->whereNull('owner_id')
            ->update(['owner_id' => $user->id, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
        if (! $won) {
            throw ValidationException::withMessages(['order' => __('Someone else took this order a moment ago.')]);
        }

        $this->openAssignment($order, $user->id, 'claimed', null);
        $this->note($order, 'assignment', __(':n took the order.', ['n' => $user->name]), $user);

        return $order->refresh();
    }

    /** Admin only: move ownership, with a reason (it can count against the previous owner). */
    public function reassign(Order $order, User $by, User $to, int $reasonId): Order
    {
        return DB::transaction(function () use ($order, $by, $to, $reasonId) {
            $previous = $order->owner_id;
            DB::table('order_assignments')->where('order_id', $order->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            $order->forceFill(['owner_id' => $to->id, 'lock_version' => $order->lock_version + 1])->save();
            $this->openAssignment($order, $to->id, 'reassigned', $by->id, $reasonId);

            $reason = DB::table('status_reasons')->where('id', $reasonId)->value('label_en');
            $from = $previous ? DB::table('users')->where('id', $previous)->value('name') : __('nobody');
            $this->note($order, 'assignment', __('Owner changed from :a to :b · :r', ['a' => $from, 'b' => $to->name, 'r' => $reason]), $by,
                ['previous_owner_id' => $previous, 'reason_id' => $reasonId]);

            return $order->refresh();
        });
    }

    public function note(Order $order, string $type, string $body, ?User $by, array $meta = []): void
    {
        DB::table('order_notes')->insert([
            'order_id' => $order->id, 'note_type' => $type, 'body' => $body, 'meta' => $meta ? json_encode($meta) : null,
            'status_at_time_id' => $order->status_id, 'user_id' => $by?->id, 'created_at' => now(),
        ]);
    }

    /** Advance payments start as "pending verification"; only verified money reduces COD. */
    public function addPayment(Order $order, array $p, ?User $by): void
    {
        $method = DB::table('payment_methods')->where('id', $p['method_id'] ?? 0)->first();
        if (! $method) {
            throw ValidationException::withMessages(['advance.method_id' => __('Choose how the customer paid.')]);
        }
        if ($method->requires_trx_id && blank($p['transaction_id'] ?? null)) {
            throw ValidationException::withMessages(['advance.transaction_id' => __('Transaction ID is required for :m.', ['m' => $method->name])]);
        }
        if (! blank($p['transaction_id'] ?? null) && DB::table('order_payments')->where('method_id', $method->id)->where('transaction_id', trim($p['transaction_id']))->exists()) {
            throw ValidationException::withMessages(['advance.transaction_id' => __('This transaction ID was already used on another order.')]);
        }

        DB::table('order_payments')->insert([
            'order_id' => $order->id, 'payment_type' => $p['payment_type'] ?? 'advance', 'method_id' => $method->id,
            'amount' => round((float) $p['amount'], 2), 'transaction_id' => ($p['transaction_id'] ?? null) ? trim($p['transaction_id']) : null,
            'sender_number' => Phone::normalize($p['sender_number'] ?? null), 'status' => 'pending_verification',
            'received_at' => now(), 'note' => $p['note'] ?? null, 'created_by' => $by?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->note($order, 'payment', __('Advance ৳:a via :m (:t) recorded, waiting for verification.', ['a' => number_format((float) $p['amount'], 2), 'm' => $method->name, 't' => $p['transaction_id'] ?? '-']), $by);
    }

    public function verifyPayment(Order $order, int $paymentId, bool $approve, User $by): void
    {
        DB::transaction(function () use ($order, $paymentId, $approve, $by) {
            $payment = DB::table('order_payments')->where('id', $paymentId)->where('order_id', $order->id)->where('status', 'pending_verification')->first();
            abort_unless($payment, 404);
            DB::table('order_payments')->where('id', $paymentId)->update([
                'status' => $approve ? 'verified' : 'rejected', 'verified_by' => $by->id, 'verified_at' => now(), 'updated_at' => now(),
            ]);
            $this->calculator->applyPayments($order->refresh());
            $this->note($order, 'payment', ($approve ? __('Payment ৳:a verified. COD is now ৳:c.', ['a' => $payment->amount, 'c' => $order->cod_amount]) : __('Payment ৳:a rejected.', ['a' => $payment->amount])), $by);
        });
    }

    /** @return list<array> order_items rows with snapshots and weights */
    public function buildItems(array $rows, ?User $by, bool $trustGivenPrice, array $alreadyOnOrder = []): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => ! empty($r['variant_id']) && (float) ($r['qty'] ?? 0) > 0));
        if ($rows === []) {
            throw ValidationException::withMessages(['items' => __('Add at least one product.')]);
        }

        $onlineId = DB::table('price_lists')->where('system_key', 'online')->value('id');
        $variants = ProductVariant::with(['product:id,name', 'prices' => fn ($q) => $q->where('price_list_id', $onlineId)])
            ->whereIn('id', array_column($rows, 'variant_id'))->get()->keyBy('id');
        $mayOverride = $trustGivenPrice || $by?->can('orders.approve');

        return array_map(function ($r) use ($variants, $mayOverride, $trustGivenPrice, $alreadyOnOrder) {
            $v = $variants[$r['variant_id']] ?? throw ValidationException::withMessages(['items' => __('A product in this order no longer exists.')]);
            // A line already on the order may stay even if the item has since gone out of stock.
            if (! $trustGivenPrice && $v->availability_status === 'out_of_stock' && ! in_array($v->id, $alreadyOnOrder, true)) {
                throw ValidationException::withMessages(['items' => __(':p is out of stock.', ['p' => $v->product->name.' · '.$v->name])]);
            }
            $listPrice = (float) ($v->prices->first()?->effective() ?? 0);
            $price = $mayOverride && isset($r['unit_price']) && $r['unit_price'] !== '' ? (float) $r['unit_price'] : $listPrice;
            $qty = round((float) $r['qty'], 3);
            $discount = round((float) ($r['line_discount'] ?? 0), 2);

            return [
                'variant_id' => $v->id,
                'name_snapshot' => $v->product->name.($v->name !== 'Default' ? ' · '.$v->name : ''),
                'sku_snapshot' => $v->sku,
                'unit' => $v->unit,
                'qty' => $qty,
                'unit_price' => $price,
                'line_discount' => $discount,
                'line_total' => round(max(0, $qty * $price - $discount), 2),
                'weight_g' => (int) round($v->unit === 'g' ? $qty : ($v->weight_g ?? 0) * $qty),
            ];
        }, $rows);
    }

    /** Append-only full snapshot of the order as it is now. */
    public function snapshot(Order $order, int $version, ?int $userId): void
    {
        DB::table('order_versions')->insert([
            'order_id' => $order->id,
            'version_no' => $version,
            'items_snapshot' => json_encode(DB::table('order_items')->where('order_id', $order->id)
                ->get(['variant_id', 'name_snapshot', 'sku_snapshot', 'unit', 'qty', 'unit_price', 'line_discount', 'line_total'])),
            'totals_snapshot' => json_encode($order->only(['subtotal', 'discount_total', 'delivery_charge', 'grand_total', 'advance_verified', 'cod_amount'])),
            'shipping_snapshot' => json_encode($order->only(['ship_name', 'ship_phone', 'ship_alt_phone', 'ship_address', 'ship_district', 'ship_thana', 'zone_id'])),
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    private function openAssignment(Order $order, int $userId, string $how, ?int $by, ?int $reasonId = null): void
    {
        DB::table('order_assignments')->insert([
            'order_id' => $order->id, 'user_id' => $userId, 'role' => 'owner', 'how' => $how,
            'assigned_by' => $by, 'reason_id' => $reasonId, 'started_at' => now(),
        ]);
    }
}
