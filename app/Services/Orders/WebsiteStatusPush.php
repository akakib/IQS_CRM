<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Catalog\Store\StoreDriver;
use Illuminate\Support\Facades\Log;

/**
 * A website order follows its IQS status on the website too, for the statuses switched on (config
 * store.order_statuses; Record verified only unless more is set). The website's own "Record verified" fires
 * its Meta Purchase, so an order that passes it here without the website having had it (confirmed after an
 * advance, say) is set to Record verified first. Sent after the response; if the website does not take it,
 * the reason is kept on the order and the desk shows the button to send it. A finished website order
 * (completed, refunded, failed, in the bin) and one the customer cancelled there are never touched.
 */
class WebsiteStatusPush
{
    public const STATUS = 'record-verified';

    /**
     * The website's slug for Courier Processing: cut to 17 letters there, because a status with "wc-" in front
     * holds 20 at most (made on the website in Top5Way Tracking > Order Status).
     */
    public const COURIER = 'courier-processin';

    /** IQS status => the website's status slug. Packaging to In transit are one status there: Courier Processing. */
    public const MAP = [
        'record_verified' => 'record-verified', 'no_answer' => 'no-response', 'hold' => 'on-hold', 'confirmed' => 'call-verified',
        'ready_for_packaging' => self::COURIER, 'packed' => self::COURIER, 'ready_for_pickup' => self::COURIER,
        'handed_over' => self::COURIER, 'in_transit' => self::COURIER,
        'delivered' => 'completed', 'partial_delivered' => 'completed', 'returned' => 'returned', 'cancelled' => 'cancelled',
    ];

    /** Ended on the website: left as it is. */
    private const ENDED = ['completed', 'refunded', 'failed', 'trash', 'cancelled'];

    /** Already at or past Record verified on the website: Record verified is not sent (again). */
    private const PAST_VERIFIED = [self::STATUS, 'call-verified', self::COURIER, 'returned'];

    /** IQS statuses that mean the order was verified: the website must have had Record verified by then. */
    private const VERIFIED_HERE = ['record_verified', 'confirmed', 'ready_for_packaging', 'packed', 'ready_for_pickup', 'handed_over', 'in_transit', 'delivered', 'partial_delivered'];

    public function __construct(private StoreDriver $store) {}

    /** A website status as people read it ("courier-processin" is Courier Processing). */
    public static function label(?string $slug): string
    {
        return $slug === self::COURIER ? 'Courier Processing' : \Illuminate\Support\Str::headline((string) $slug);
    }

    /** @return list<string> the website statuses to set now, in order (empty: nothing to send) */
    public function plan(Order $order): array
    {
        if ($order->channel !== 'web' || blank($order->external_ref)) {
            return [];
        }
        $now = (string) $order->external_status;
        if (in_array($now, self::ENDED, true)) {
            return [];
        }
        $on = (array) config('store.order_statuses');
        $key = \App\Models\OrderStatus::map()[$order->status_id]['key'] ?? null;
        $steps = [];
        if (in_array(self::STATUS, $on, true) && in_array($key, self::VERIFIED_HERE, true) && ! in_array($now, self::PAST_VERIFIED, true)) {
            $steps[] = self::STATUS;
        }
        $target = self::MAP[$key] ?? null;
        if ($target && $target !== self::STATUS && in_array($target, $on, true) && $target !== $now) {
            $steps[] = $target;
        }

        return $steps;
    }

    public function applies(Order $order): bool
    {
        return $this->plan($order) !== [];
    }

    /** A person may send it whenever something is waiting to go. */
    public function canSend(Order $order): bool
    {
        return $this->applies($order);
    }

    /** The website status the order ends on when sent now (null: nothing to send). */
    public function target(Order $order): ?string
    {
        $plan = $this->plan($order);

        return $plan ? end($plan) : null;
    }

    /** Send it now; true when the website took it. */
    public function push(Order $order, ?User $by = null): bool
    {
        $plan = $this->plan($order);
        if ($plan === []) {
            return false;
        }
        $set = null;
        try {
            foreach ($plan as $status) {
                $this->store->setOrderStatus((string) $order->external_ref, $status);
                $set = $status;
            }
        } catch (\Throwable $e) {
            $order->forceFill(array_filter(['external_status' => $set]) + ['website_push_error' => mb_substr($e->getMessage(), 0, 250)])->save();
            Log::warning('Website status push failed', ['order' => $order->order_no, 'error' => $e->getMessage()]);

            return false;
        }
        $order->forceFill(['external_status' => $set, 'website_pushed_at' => now(), 'website_push_error' => null])->save();
        $name = self::label($set);
        app(OrderService::class)->note($order, 'system', $this->store->name() === 'fake'
            ? __('Test server: :s not sent to the website (no live connection).', ['s' => $name])
            : __('Website order set to :s.', ['s' => $name]), $by);

        return true;
    }
}
