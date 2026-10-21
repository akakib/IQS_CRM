<?php

namespace App\Services\Catalog\Store;

/**
 * Staging connected to a TEST website (STORE_ORDER_STATUS_LIVE=true): order statuses go to that website for real, so
 * Record verified can be tested end to end, while catalog pushes (prices, stock) stay fake.
 */
class SplitStoreDriver implements StoreDriver
{
    public function __construct(private StoreDriver $catalog, private StoreDriver $orders) {}

    public function name(): string
    {
        return $this->orders->name();
    }

    public function pushVariant(object $link, array $payload): void
    {
        $this->catalog->pushVariant($link, $payload);
    }

    public function setOrderStatus(string $orderId, string $status): void
    {
        $this->orders->setOrderStatus($orderId, $status);
    }
}
