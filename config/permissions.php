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
        'products' => ['view', 'create', 'edit', 'delete', 'export', 'availability'],
        'customers' => ['view', 'create', 'edit', 'delete', 'export'],
        'orders' => ['view', 'create', 'edit', 'approve', 'export', 'reassign', 'take'],
        'shipping' => ['view', 'create'],
        'hotline' => ['view', 'create'],
        'packing' => ['view', 'create', 'manage'],
        'attendance' => ['view', 'edit'],
        // Creating/editing roles and giving access is Owner-only (no permission
        // can grant it, so nobody can raise their own access).
        'roles' => ['view'],
        // Everyone sees their own points; "manage" = rules, reviews, disputes, QA.
        'points' => ['manage'],
        'kpi' => ['view'],
        'analysis' => ['view'],
        'marketing' => ['view', 'create'],
        'activity' => ['view'],
        'settings' => ['view', 'edit'],
    ],

    // Display names where the plain action word would mislead.
    'action_labels' => [
        'staff.delete' => 'Deactivate',
        'products.availability' => 'Stock status',
        'points.manage' => 'Manage rules and reviews',
        'packing.manage' => 'Set on-duty packers',
        'attendance.view' => 'See breaks and working days',
        'attendance.edit' => 'Correct breaks, approve extra days',
        'orders.reassign' => 'Reassign and control room',
        'orders.take' => 'Take orders from the queue (moderator)',
        'marketing.create' => 'Add spend and USD lots',
    ],

    // Fields a role can have hidden (masked).
    'field_masks' => ['customer_contact', 'cost_price', 'profit', 'salary'],

    // Hard cap on how long a computed permission map is cached (seconds).
    'cache_ttl' => 3600,
];
