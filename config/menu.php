<?php

/*
| Sidebar: groups => items [label, route, active pattern, permission, icon].
| An item shows only if its route exists (modules not built yet stay hidden)
| and the user has its permission. A group with no visible item is hidden.
| `active` is a route-name pattern. Icons are names from <x-icon>.
*/

return [
    'overview' => ['Overview', [
        ['Dashboard', 'dashboard', 'dashboard', null, 'home'],
        ['My points', 'points.mine', 'points.mine', null, 'star'],
    ]],
    'orders' => ['Orders', [
        ['Order activity', 'orders.activity', 'orders.activity', 'orders.reassign', 'board'],
        ['Order management', 'desk.index', 'desk.index', 'orders.edit', 'clipboard'],
        ['All orders', 'orders.index', 'orders.index', 'orders.view', 'list'],
        ['Quick order', 'orders.create', 'orders.create', 'orders.create', 'plus'],
        ['Courier booking', 'shipping.index', 'shipping.*', 'shipping.view', 'truck'],
        ['Packaging', 'packaging.index', 'packaging.*', 'packaging.view', 'box'],
        ['Handover', 'handover.index', 'handover.*', 'packaging.view', 'swap'],
        ['Hotline', 'hotline.index', 'hotline.*', 'hotline.view', 'phone'],
        ['Delivery issues', 'issues.index', 'issues.*', 'orders.view', 'alert'],
        ['Payments to check', 'payments.index', 'payments.*', 'payments.verify', 'cash'],
        ['Control room', 'desk.control', 'desk.control', 'orders.reassign', 'monitor'],
    ]],
    'catalog' => ['Catalog', [
        ['Products', 'products.index', 'products.*', 'products.view', 'tag'],
        ['Categories', 'categories.index', 'categories.*', 'products.edit', 'list'],
        ['Customers', 'customers.index', 'customers.*', 'customers.view', 'users'],
    ]],
    'analysis' => ['Analysis', [
        ['KPI', 'kpi.index', 'kpi.*', null, 'chart'],
        ['Order P&L', 'analysis.index', 'analysis.*', 'analysis.view', 'calculator'],
    ]],
    'marketing' => ['Marketing', [
        ['Ad spend & ROAS', 'marketing.index', 'marketing.*', 'marketing.view', 'megaphone'],
        ['USD lots', 'usd-lots.index', 'usd-lots.*', 'marketing.view', 'dollar'],
    ]],
    'stock' => ['Stock', [
        ['Locations', 'locations.index', 'locations.*', 'locations.view', 'pin'],
    ]],
    'accounting' => ['Accounting', []],
    'team' => ['Team', [
        ['Staff', 'users.index', 'users.*', 'staff.view', 'users'],
        ['Roles', 'roles.index', 'roles.*', 'roles.view', 'shield'],
        ['Attendance & breaks', 'attendance.index', 'attendance.*', 'attendance.view', 'clock'],
        ['Points review', 'points.review', 'points.review*', 'points.manage', 'flag'],
    ]],
    'settings' => ['Settings', [
        ['General', 'settings.edit', 'settings.edit', 'settings.view', 'cog'],
        ['Notifications', 'settings.notifications', 'settings.notifications*', 'settings.view', 'bell'],
        ['Verification rules', 'settings.verification', 'settings.verification*', 'settings.view', 'check'],
        ['Delivery charges', 'settings.charges', 'settings.charges*', 'settings.view', 'cash'],
        ['Ad tracking', 'settings.tracking', 'settings.tracking*', 'settings.view', 'cursor'],
        ['Points rules', 'settings.points', 'settings.points*', 'points.manage', 'star'],
        ['Statuses & reasons', 'settings.reasons', 'settings.reasons*', 'settings.view', 'tag'],
        ['Activity log', 'activity.index', 'activity.*', 'activity.view', 'document'],
        ['Website connection', 'settings.integrations', 'settings.integrations*', 'access.manage', 'globe'],
        ['Courier accounts', 'settings.couriers', 'settings.couriers*', 'access.manage', 'truck'],
        ['System health', 'health', 'health', 'access.manage', 'heart'],
        ['Components (dev)', 'dev.components', 'dev.*', 'access.manage', 'code'],
    ]],
];
