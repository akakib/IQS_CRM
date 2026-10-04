<?php

/*
| Permission catalogue: module => actions. `php artisan permissions:sync`
| copies it into the permissions table. A module added here appears in every
| role as NOT allowed until an admin ticks it. Owner always has everything.
*/

return [
    'modules' => [
        'staff' => ['view', 'create', 'edit', 'delete', 'export'],
        'locations' => ['view', 'create', 'edit', 'delete'],
        // Creating/editing roles and giving access is Owner-only (no permission
        // can grant it, so nobody can raise their own access).
        'roles' => ['view'],
    ],

    // Display names where the plain action word would mislead.
    'action_labels' => [
        'staff.delete' => 'Deactivate',
    ],

    // Fields a role can have hidden (masked).
    'field_masks' => ['customer_contact', 'cost_price', 'profit', 'salary'],

    // Hard cap on how long a computed permission map is cached (seconds).
    'cache_ttl' => 3600,
];
