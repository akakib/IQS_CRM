<?php

return [
    // fake | woocommerce. Testing, staging and local always use fake.
    'driver' => env('STORE_DRIVER', 'fake'),

    // Staging connected to a TEST website only: send order statuses (Record verified) to it for real; prices and stock stay fake.
    'order_status_live' => (bool) env('STORE_ORDER_STATUS_LIVE', false),

    // What a product change may send to the website: any of price, stock_status, content. Stock only unless set, so a
    // wrong price or text typed here never reaches customers; the website stays the place for prices and texts.
    'push_fields' => array_values(array_filter(array_map('trim', explode(',', (string) env('STORE_PUSH_FIELDS', 'stock_status'))))),

    'woocommerce' => [
        'url' => env('WOO_URL'),
        'key' => env('WOO_KEY'),
        'secret' => env('WOO_SECRET'),
        'webhook_secret' => env('WOO_WEBHOOK_SECRET'),
    ],
];
