<?php

namespace App\Services\Customers\Drivers;

use App\Models\Customer;
use App\Services\Courier\Data\FraudCheckResult;
use App\Services\Customers\FraudCheckDriver;

/** Our own history: delivered vs returned parcels IQS saw itself (imported files are not proof). */
class InternalFraudDriver implements FraudCheckDriver
{
    public function check(Customer $customer, string $phone, ?array $credentials): FraudCheckResult
    {
        $delivered = (int) $customer->iqs_delivered_count;
        $returned = (int) $customer->iqs_returned_count;

        return new FraudCheckResult($phone, $delivered + $returned, $delivered, $returned, ['source' => 'internal']);
    }
}
