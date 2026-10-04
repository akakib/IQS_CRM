<?php

namespace App\Services\Courier\Data;

final class BookingRequest
{
    public function __construct(
        public readonly string $invoice,
        public readonly string $recipientName,
        public readonly string $recipientPhone,
        public readonly string $recipientAddress,
        public readonly float $codAmount,
        public readonly ?string $note = null,
    ) {}
}
