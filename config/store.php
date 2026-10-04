<?php

return [
    // fake | woocommerce. Testing, staging and local always use fake.
    'driver' => env('STORE_DRIVER', 'fake'),

    'woocommerce' => [
        'url' => env('WOO_URL'),
        'key' => env('WOO_KEY'),
        'secret' => env('WOO_SECRET'),
        'webhook_secret' => env('WOO_WEBHOOK_SECRET'),
    ],
];
