<?php

namespace App\Services\Courier;

use App\Services\Courier\Data\BookingRequest;
use App\Services\Courier\Data\BookingResult;
use App\Services\Courier\Data\CourierUpdate;
use App\Services\Courier\Data\FraudCheckResult;

/**
 * One courier (Steadfast now, Pathao/RedX later). Steadfast has no sandbox:
 * every real booking is a real parcel, so tests and staging always get the
 * fake driver (see CourierManager).
 */
interface CourierDriver
{
    public function name(): string;

    /**
     * Book many parcels at once. Idempotent by invoice (our order number):
     * booking the same invoice twice returns the first consignment.
     *
     * @param  list<BookingRequest>  $requests
     * @return array<string, BookingResult> invoice => result
     */
    public function bookBulk(array $requests): array;

    /** Current courier status for one of our invoices, or null if unknown. */
    public function statusByInvoice(string $invoice): ?CourierUpdate;

    /** Tracking messages, oldest first. @return list<CourierUpdate> */
    public function trackingByInvoice(string $invoice): array;

    /** Delivery history of a phone number across the courier's network. */
    public function fraudCheck(string $phone): FraudCheckResult;

    /** Turn a raw webhook body into a normalised update (null = ignore). */
    public function parseWebhook(array $payload): ?CourierUpdate;
}
