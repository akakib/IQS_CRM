<?php

namespace Database\Seeders;

use App\Services\Customers\Drivers\InternalFraudDriver;
use App\Services\Customers\Drivers\SteadfastFraudDriver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['inside_dhaka', 'Inside Dhaka', 1],
            ['sub_dhaka', 'Dhaka sub-area', 2],
            ['outside_dhaka', 'Outside Dhaka', 3],
        ] as [$key, $name, $order]) {
            if (! DB::table('delivery_zones')->where('system_key', $key)->exists()) {
                DB::table('delivery_zones')->insert(['system_key' => $key, 'name' => $name, 'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach ([
            ['internal', 'Our own history', InternalFraudDriver::class, 0, 1],
            ['steadfast', 'Steadfast', SteadfastFraudDriver::class, 24, 2],
        ] as [$key, $name, $class, $hours, $order]) {
            if (! DB::table('fraud_check_providers')->where('system_key', $key)->exists()) {
                DB::table('fraud_check_providers')->insert([
                    'system_key' => $key, 'name' => $name, 'driver_class' => $class, 'cache_hours' => $hours,
                    'is_active' => true, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
