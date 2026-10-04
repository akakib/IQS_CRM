<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Services\Courier\Data\FraudCheckResult;

/** One delivery-history source (a courier network, or our own orders). Add a vendor = a class + a provider row. */
interface FraudCheckDriver
{
    public function check(Customer $customer, string $phone, ?array $credentials): FraudCheckResult;
}
