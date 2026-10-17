<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Catalog\Store\StoreDriver;
use Illuminate\Support\Facades\Log;

/**
 * A website order that IQS record-verifies is set to the website's own "Record verified" status
 * (record-verified), which fires the website's Meta Purchase. Done after the response when the order
 * reaches record_verified; if the website does not take it, the reason is kept on the order and the
 * desk shows Send to website. Never moves a website order back from done, cancelled or refunded.
 */
class WebsiteStatusPush
{
    public const STATUS = 'record-verified';

    private const LEAVE = [self::STATUS, 'completed', 'cancelled', 'refunded', 'failed', 'trash'];

    public function __construct(private StoreDriver $store) {}

    public function applies(Order $order): bool
    {
        return $order->channel === 'web' && filled($order->external_ref) && ! in_array((string) $order->external_status, self::LEAVE, true);
    }

    /** A person may send it: a website order IQS has record-verified (or moved past), not one still new, held or ended. */
    public function canSend(Order $order): bool
    {
        $key = \App\Models\OrderStatus::map()[$order->status_id]['key'] ?? null;

        return $this->applies($order) && ! in_array($key, ['new', 'hold', 'cancelled', 'returned'], true);
    }

    /** Send it now; true when the website took it. */
    public function push(Order $order, ?User $by = null): bool
    {
        if (! $this->applies($order)) {
            return false;
        }
        try {
            $this->store->setOrderStatus((string) $order->external_ref, self::STATUS);
        } catch (\Throwable $e) {
            $order->forceFill(['website_push_error' => mb_substr($e->getMessage(), 0, 250)])->save();
            Log::warning('Website status push failed', ['order' => $order->order_no, 'error' => $e->getMessage()]);

            return false;
        }
        $order->forceFill(['external_status' => self::STATUS, 'website_pushed_at' => now(), 'website_push_error' => null])->save();
        app(OrderService::class)->note($order, 'system', __('Website order set to Record verified.'), $by);

        return true;
    }
}
