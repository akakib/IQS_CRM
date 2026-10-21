<?php

return [
    // fake | woocommerce. Testing, staging and local always use fake.
    'driver' => env('STORE_DRIVER', 'fake'),

    // Staging connected to a TEST website only: send order statuses (Record verified) to it for real; prices and stock stay fake.
    'order_status_live' => (bool) env('STORE_ORDER_STATUS_LIVE', false),

    'woocommerce' => [
        'url' => env('WOO_URL'),
        'key' => env('WOO_KEY'),
        'secret' => env('WOO_SECRET'),
        'webhook_secret' => env('WOO_WEBHOOK_SECRET'),
    ],
];
