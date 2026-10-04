<?php

namespace App\Services\Courier\Data;

final class CourierUpdate
{
    /**
     * @param  string  $type  'delivery_status' | 'tracking_update'
     * @param  string|null  $status  courier's own status word, e.g. 'delivered', 'partial_delivered', 'cancelled', 'hold'
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $consignmentId,
        public readonly ?string $invoice,
        public readonly ?string $status = null,
        public readonly ?float $codAmount = null,
        public readonly ?float $deliveryCharge = null,
        public readonly ?string $message = null,
        public readonly ?string $occurredAt = null,
        public readonly array $raw = [],
    ) {}
}
