<?php

namespace App\Services\Catalog\Store;

/** The website (WooCommerce now, Shopify later) as a receiver of catalog updates. */
interface StoreDriver
{
    public function name(): string;

    /**
     * @param  object  $link  channel_product_links row (external ids)
     * @param  array  $payload  sku + only the changed field groups
     */
    public function pushVariant(object $link, array $payload): void;
}
