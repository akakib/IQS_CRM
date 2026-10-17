<?php

namespace App\Services\Catalog\Store;

/** The website (WooCommerce now, Shopify later) as a receiver of catalog updates and order statuses. */
interface StoreDriver
{
    public function name(): string;

    /**
     * @param  object  $link  channel_product_links row (external ids)
     * @param  array  $payload  sku + only the changed field groups
     */
    public function pushVariant(object $link, array $payload): void;

    /** Set a website order's status (its own slug, e.g. record-verified); throws when the website refuses. */
    public function setOrderStatus(string $orderId, string $status): void;
}
