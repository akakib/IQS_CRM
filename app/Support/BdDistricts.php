<?php

namespace App\Support;

/**
 * WooCommerce saves a Bangladesh district as its code ("BD-10"); people read the name ("Chattogram").
 * The list is WooCommerce's own (GET /wc/v3/data/countries/bd), so the codes match the website.
 */
class BdDistricts
{
    private const NAMES = [
        'BD-01' => 'Bandarban', 'BD-02' => 'Barguna', 'BD-03' => 'Bogura', 'BD-04' => 'Brahmanbaria', 'BD-05' => 'Bagerhat', 'BD-06' => 'Barishal',
        'BD-07' => 'Bhola', 'BD-08' => 'Cumilla', 'BD-09' => 'Chandpur', 'BD-10' => 'Chattogram', 'BD-11' => "Cox's Bazar", 'BD-12' => 'Chuadanga',
        'BD-13' => 'Dhaka', 'BD-14' => 'Dinajpur', 'BD-15' => 'Faridpur', 'BD-16' => 'Feni', 'BD-17' => 'Gopalganj', 'BD-18' => 'Gazipur',
        'BD-19' => 'Gaibandha', 'BD-20' => 'Habiganj', 'BD-21' => 'Jamalpur', 'BD-22' => 'Jashore', 'BD-23' => 'Jhenaidah', 'BD-24' => 'Joypurhat',
        'BD-25' => 'Jhalokati', 'BD-26' => 'Kishoreganj', 'BD-27' => 'Khulna', 'BD-28' => 'Kurigram', 'BD-29' => 'Khagrachhari', 'BD-30' => 'Kushtia',
        'BD-31' => 'Lakshmipur', 'BD-32' => 'Lalmonirhat', 'BD-33' => 'Manikganj', 'BD-34' => 'Mymensingh', 'BD-35' => 'Munshiganj', 'BD-36' => 'Madaripur',
        'BD-37' => 'Magura', 'BD-38' => 'Moulvibazar', 'BD-39' => 'Meherpur', 'BD-40' => 'Narayanganj', 'BD-41' => 'Netrakona', 'BD-42' => 'Narsingdi',
        'BD-43' => 'Narail', 'BD-44' => 'Natore', 'BD-45' => 'Nawabganj', 'BD-46' => 'Nilphamari', 'BD-47' => 'Noakhali', 'BD-48' => 'Naogaon',
        'BD-49' => 'Pabna', 'BD-50' => 'Pirojpur', 'BD-51' => 'Patuakhali', 'BD-52' => 'Panchagarh', 'BD-53' => 'Rajbari', 'BD-54' => 'Rajshahi',
        'BD-55' => 'Rangpur', 'BD-56' => 'Rangamati', 'BD-57' => 'Sherpur', 'BD-58' => 'Satkhira', 'BD-59' => 'Sirajganj', 'BD-60' => 'Sylhet',
        'BD-61' => 'Sunamganj', 'BD-62' => 'Shariatpur', 'BD-63' => 'Tangail', 'BD-64' => 'Thakurgaon',
    ];

    /** "BD-10" -> "Chattogram"; a name or anything else is returned as it is. */
    public static function name(?string $value): ?string
    {
        $v = trim((string) $value);

        return $v === '' ? null : (self::NAMES[strtoupper($v)] ?? $v);
    }
}
