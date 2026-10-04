<?php

namespace App\Services\Courier;

use App\Services\Courier\Data\CourierUpdate;

/**
 * Steadfast webhook body -> CourierUpdate. Two shapes arrive on the same URL,
 * told apart by notification_type: 'delivery_status' (has `status`) and
 * 'tracking_update' (only a message). Shared by the real and fake drivers.
 */
final class SteadfastPayload
{
    public static function parse(array $payload): ?CourierUpdate
    {
        $type = $payload['notification_type'] ?? 'delivery_status';
        if (! in_array($type, ['delivery_status', 'tracking_update'], true)) {
            return null;
        }

        $consignmentId = isset($payload['consignment_id']) ? (string) $payload['consignment_id'] : null;
        $invoice = isset($payload['invoice']) ? (string) $payload['invoice'] : null;

        if ($consignmentId === null && $invoice === null) {
            return null;
        }

        return new CourierUpdate(
            type: $type,
            consignmentId: $consignmentId,
            invoice: $invoice,
            status: isset($payload['status']) ? strtolower((string) $payload['status']) : null,
            codAmount: isset($payload['cod_amount']) ? (float) $payload['cod_amount'] : null,
            deliveryCharge: isset($payload['delivery_charge']) ? (float) $payload['delivery_charge'] : null,
            message: $payload['tracking_message'] ?? null,
            occurredAt: $payload['updated_at'] ?? null,
            raw: $payload,
        );
    }
}
