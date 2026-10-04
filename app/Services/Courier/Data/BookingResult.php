<?php

namespace App\Services\Courier\Data;

final class BookingResult
{
    public function __construct(
        public readonly string $invoice,
        public readonly bool $ok,
        public readonly ?string $consignmentId = null,
        public readonly ?string $trackingCode = null,
        public readonly ?string $status = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
    ) {}
}
