<?php

namespace App\Services\Customers\Drivers;

use App\Models\Customer;
use App\Services\Courier\CourierManager;
use App\Services\Courier\Data\FraudCheckResult;
use App\Services\Customers\FraudCheckDriver;

/** Steadfast network history, through the courier driver (fake on staging). */
class SteadfastFraudDriver implements FraudCheckDriver
{
    public function __construct(private CourierManager $courier) {}

    public function check(Customer $customer, string $phone, ?array $credentials): FraudCheckResult
    {
        return $this->courier->driver()->fraudCheck($phone);
    }
}
