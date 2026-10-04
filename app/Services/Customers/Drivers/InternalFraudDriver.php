<?php

namespace App\Services\Customers\Drivers;

use App\Models\Customer;
use App\Services\Courier\Data\FraudCheckResult;
use App\Services\Customers\FraudCheckDriver;

/** Our own history: delivered vs returned parcels of this customer. */
class InternalFraudDriver implements FraudCheckDriver
{
    public function check(Customer $customer, string $phone, ?array $credentials): FraudCheckResult
    {
        $delivered = (int) $customer->delivered_count;
        $returned = (int) $customer->returned_count;

        return new FraudCheckResult($phone, $delivered + $returned, $delivered, $returned, ['source' => 'internal']);
    }
}
