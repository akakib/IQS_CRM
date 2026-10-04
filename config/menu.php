<?php

/*
| Sidebar: groups => items. An item shows only if its route exists (modules
| not built yet stay hidden) and the user has its permission. A group with
| no visible item is hidden. `active` is a route-name pattern.
*/

return [
    'overview' => ['Overview', [
        ['Dashboard', 'dashboard', 'dashboard', null],
    ]],
    'orders' => ['Orders', [
        ['All orders', 'orders.index', 'orders.index', 'orders.view'],
        ['Call queue', 'orders.queue', 'orders.queue', 'orders.edit'],
        ['Quick order', 'orders.create', 'orders.create', 'orders.create'],
        ['Packing', 'packing.index', 'packing.*', 'packing.view'],
        ['Handover', 'handover.index', 'handover.*', 'packing.view'],
        ['Hotline', 'hotline.index', 'hotline.*', 'hotline.view'],
    ]],
    'catalog' => ['Catalog', [
        ['Products', 'products.index', 'products.*', 'products.view'],
        ['Customers', 'customers.index', 'customers.*', 'customers.view'],
    ]],
    'analysis' => ['Analysis', [
        ['Dashboard & KPI', 'kpi.index', 'kpi.*', 'kpi.view'],
        ['Order P&L', 'analysis.index', 'analysis.*', 'analysis.view'],
    ]],
    'marketing' => ['Marketing', [
        ['Ad spend & ROAS', 'marketing.index', 'marketing.*', 'marketing.view'],
        ['USD lots', 'usd-lots.index', 'usd-lots.*', 'marketing.view'],
    ]],
    'stock' => ['Stock', [
        ['Locations', 'locations.index', 'locations.*', 'locations.view'],
    ]],
    'accounting' => ['Accounting', []],
    'team' => ['Team', [
        ['Staff', 'users.index', 'users.*', 'staff.view'],
        ['Roles', 'roles.index', 'roles.*', 'roles.view'],
    ]],
    'settings' => ['Settings', [
        ['General', 'settings.edit', 'settings.edit', 'settings.view'],
        ['Notifications', 'settings.notifications', 'settings.notifications*', 'settings.view'],
        ['Verification rules', 'settings.verification', 'settings.verification*', 'settings.view'],
        ['Delivery charges', 'settings.charges', 'settings.charges*', 'settings.view'],
        ['Ad tracking', 'settings.tracking', 'settings.tracking*', 'settings.view'],
        ['Statuses & reasons', 'settings.reasons', 'settings.reasons*', 'settings.view'],
        ['Activity log', 'activity.index', 'activity.*', 'activity.view'],
        ['Website connection', 'settings.integrations', 'settings.integrations*', 'access.manage'],
        ['System health', 'health', 'health', 'access.manage'],
        ['Components (dev)', 'dev.components', 'dev.*', 'access.manage'],
    ]],
];
