<?php

return [
    // fake | steadfast. Testing, staging and local always use fake (see CourierManager).
    'driver' => env('COURIER_DRIVER', 'fake'),

    // Escape hatch for a developer deliberately testing the real API locally.
    'allow_real_in_local' => (bool) env('COURIER_ALLOW_REAL_IN_LOCAL', false),

    'steadfast' => [
        'base_url' => 'https://portal.packzy.com/api/v1',
        'api_key' => env('STEADFAST_API_KEY'),
        'secret_key' => env('STEADFAST_SECRET_KEY'),
        'webhook_token' => env('STEADFAST_WEBHOOK_TOKEN'),
    ],
];
