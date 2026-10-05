<?php

/*
| Notification events. `php artisan notifications:sync` copies them into
| notification_types; who receives each one is edited in Settings >
| Notifications (the alert matrix). Code raises them only through
| App\Services\NotificationService::send('<key>', ...).
*/

return [
    'types' => [
        'new_order' => ['New order to take', 'normal'],
        'order_needs_repack' => ['Order edited after packaging (repack)', 'urgent'],
        'delivery_issue' => ['Delivery issue from rider or courier', 'urgent'],
        'stock_issue_reported' => ['Packer could not find an item', 'urgent'],
        'hold_expected_date_passed' => ['Hold expected date passed', 'normal'],
        'order_held_for_stock' => ['Order held: item out of stock or pre-order', 'normal'],
        'amendment_pending_approval' => ['Order change waiting for approval', 'normal'],
        'cod_update_needed' => ['COD changed after booking: update it at the courier', 'urgent'],
        'courier_cancel_needed' => ['Cancelled after booking: delete it at the courier', 'urgent'],
        'courier_action_pending' => ['Courier update pending (COD, rebook)', 'urgent'],
        'sync_failed' => ['Website sync failed', 'normal'],
        'oos_review_due' => ['Out of stock item due for review', 'info'],
        'vendor_payment_due' => ['Dollar vendor payment due', 'normal'],
        'order_assigned' => ['An order was given to you', 'normal'],
        'orders_unassigned' => ['Orders waiting and nobody is active', 'urgent'],
        'booking_failed' => ['Courier booking failed', 'urgent'],
        'order_held_by_packer' => ['Packer put an order on hold', 'urgent'],
        'break_not_closed' => ['Someone went on break and did not come back', 'normal'],
        'points_review' => ['Points: flag or dispute to review', 'normal'],
        'system_test' => ['Test notification', 'info'],
    ],

    // Bell polls this often (seconds). No websockets on shared hosting.
    'poll_seconds' => 45,

    // Unread items with the same group key within this window merge into one.
    'group_window_hours' => 12,

    // Dropdown shows the last N days; the history page keeps everything.
    'dropdown_days' => 30,
];
