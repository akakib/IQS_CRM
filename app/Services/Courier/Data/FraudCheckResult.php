<?php

namespace App\Services\Courier\Data;

final class FraudCheckResult
{
    public readonly ?float $successRate;

    public function __construct(
        public readonly string $phone,
        public readonly int $totalParcels,
        public readonly int $delivered,
        public readonly int $cancelled,
        public readonly array $raw = [],
    ) {
        // 1 of 1 is "100%" but means little; callers pair this with totalParcels.
        $this->successRate = $totalParcels > 0 ? round($delivered * 100 / $totalParcels, 2) : null;
    }
}
