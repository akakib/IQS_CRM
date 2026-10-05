<?php

namespace App\Services\Courier;

use App\Services\Courier\Data\BookingResult;
use App\Services\Courier\Data\CourierUpdate;
use App\Services\Courier\Data\FraudCheckResult;
use Illuminate\Support\Facades\Cache;

/**
 * Behaves like Steadfast without sending anything anywhere. Bookings are
 * remembered in the cache so status/tracking/idempotency work; the status
 * moves forward with time so staging shows a realistic journey. Fraud-check
 * numbers are derived from the phone, so the same phone always gets the
 * same history (phones ending in 0 look risky, ending in 9 look new).
 */
class FakeCourierDriver implements CourierDriver
{
    /** status reached after N minutes since booking */
    private const JOURNEY = [0 => 'in_review', 5 => 'pending', 30 => 'in_transit', 120 => 'delivered'];

    public function name(): string
    {
        return 'fake';
    }

    public function bookBulk(array $requests): array
    {
        $results = [];

        foreach ($requests as $request) {
            // Tests and staging can make the next booking fail: Cache 'fake-courier:fail' = auth | rejected | temporary.
            if ($kind = Cache::pull('fake-courier:fail')) {
                $results[$request->invoice] = new BookingResult($request->invoice, false, error: 'Fake courier: '.$kind.' failure', kind: $kind);

                continue;
            }
            $key = $this->key($request->invoice);
            $booking = Cache::get($key);

            if (! $booking) {
                $booking = [
                    'invoice' => $request->invoice,
                    'consignment_id' => (string) random_int(100000000, 999999999),
                    'tracking_code' => 'FK'.strtoupper(substr(md5($request->invoice.microtime()), 0, 8)),
                    'cod_amount' => $request->codAmount,
                    'booked_at' => now()->toIso8601String(),
                ];
                Cache::put($key, $booking, now()->addDays(60));
            }

            $results[$request->invoice] = new BookingResult(
                invoice: $request->invoice,
                ok: true,
                consignmentId: $booking['consignment_id'],
                trackingCode: $booking['tracking_code'],
                status: 'in_review',
                raw: $booking,
            );
        }

        return $results;
    }

    public function invoiceBooked(string $invoice): ?bool
    {
        return Cache::has($this->key($invoice));
    }

    public function statusByInvoice(string $invoice): ?CourierUpdate
    {
        $booking = Cache::get($this->key($invoice));
        if (! $booking) {
            return null;
        }

        return new CourierUpdate(
            type: 'delivery_status',
            consignmentId: $booking['consignment_id'],
            invoice: $invoice,
            status: $this->statusAt($booking['booked_at']),
            codAmount: (float) $booking['cod_amount'],
            deliveryCharge: 60.0,
            raw: $booking,
        );
    }

    public function trackingByInvoice(string $invoice): array
    {
        $booking = Cache::get($this->key($invoice));
        if (! $booking) {
            return [];
        }

        $minutes = now()->diffInMinutes($booking['booked_at'], true);
        $updates = [];
        foreach (self::JOURNEY as $after => $status) {
            if ($minutes >= $after) {
                $updates[] = new CourierUpdate('tracking_update', $booking['consignment_id'], $invoice, $status,
                    message: 'Fake courier: '.str_replace('_', ' ', $status),
                    occurredAt: now()->parse($booking['booked_at'])->addMinutes($after)->toIso8601String());
            }
        }

        return $updates;
    }

    public function fraudCheck(string $phone): FraudCheckResult
    {
        $digits = preg_replace('/\D/', '', $phone);
        $last = (int) substr($digits, -1);

        [$total, $delivered] = match (true) {
            $last === 9 => [0, 0],                                  // new to couriers
            $last === 0 => [8, 3],                                  // risky
            default => [5 + $last, 4 + $last],                      // mostly good
        };

        return new FraudCheckResult($phone, $total, $delivered, $total - $delivered, ['driver' => 'fake']);
    }

    public function parseWebhook(array $payload): ?CourierUpdate
    {
        return SteadfastPayload::parse($payload);
    }

    private function statusAt(string $bookedAt): string
    {
        $minutes = now()->diffInMinutes($bookedAt, true);
        $status = 'in_review';
        foreach (self::JOURNEY as $after => $s) {
            if ($minutes >= $after) {
                $status = $s;
            }
        }

        return $status;
    }

    private function key(string $invoice): string
    {
        return 'fake_courier:'.$invoice;
    }
}
