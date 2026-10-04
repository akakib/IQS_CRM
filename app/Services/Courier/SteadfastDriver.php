<?php

namespace App\Services\Courier;

use App\Services\Courier\Data\CourierUpdate;
use App\Services\Courier\Data\FraudCheckResult;
use RuntimeException;

/**
 * Real Steadfast (https://portal.packzy.com/api/v1, Api-Key + Secret-Key
 * headers). Implemented in Step 3, using only endpoints confirmed in
 * Steadfast's in-panel API guide. Until then every call refuses loudly.
 */
class SteadfastDriver implements CourierDriver
{
    public function __construct(private array $config = []) {}

    public function name(): string
    {
        return 'steadfast';
    }

    public function bookBulk(array $requests): array
    {
        throw $this->notYet(__FUNCTION__);
    }

    public function statusByInvoice(string $invoice): ?CourierUpdate
    {
        throw $this->notYet(__FUNCTION__);
    }

    public function trackingByInvoice(string $invoice): array
    {
        throw $this->notYet(__FUNCTION__);
    }

    public function fraudCheck(string $phone): FraudCheckResult
    {
        throw $this->notYet(__FUNCTION__);
    }

    public function parseWebhook(array $payload): ?CourierUpdate
    {
        return SteadfastPayload::parse($payload);
    }

    private function notYet(string $method): RuntimeException
    {
        return new RuntimeException("SteadfastDriver::{$method} is not implemented yet (Step 3).");
    }
}
