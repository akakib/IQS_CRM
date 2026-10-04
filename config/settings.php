<?php

/*
| Every global setting: key => [type, default, label, group, rules].
| Values live in the `settings` table; a key missing there uses the default.
| Read anywhere with settings('orders.packaging_cost').
*/

return [
    'store.name' => ['string', 'Iqbal Store', 'Store name', 'Store', ['required', 'string', 'max:100']],
    'store.hotline' => ['string', '', 'Rider hotline number', 'Store', ['nullable', 'regex:/^01[3-9]\d{8}$/']],
    'store.logo' => ['string', '', 'Logo', 'Store', ['nullable', 'image', 'max:1024']],

    'orders.pickup_cutoffs' => ['json', ['15:00'], 'Courier pickup cut-off times', 'Orders', ['array', 'min:1', 'max:6']],
    'orders.packaging_cost' => ['decimal', 0, 'Packaging cost per order (৳)', 'Orders', ['required', 'numeric', 'min:0', 'max:100000']],
    'orders.duplicate_window_hours' => ['int', 24, 'Same phone counts as duplicate within (hours)', 'Orders', ['required', 'integer', 'min:0', 'max:720']],
    'orders.freeze_minutes_before_pickup' => ['int', 30, 'Freeze content edits before pickup (minutes)', 'Orders', ['required', 'integer', 'min:0', 'max:600']],
    'orders.max_working_orders' => ['int', 1, 'Orders one person can work on at a time (new / record verified)', 'Orders', ['required', 'integer', 'min:1', 'max:20']],
    'orders.max_no_answer' => ['int', 3, 'No-answer tries before the order must be held or cancelled', 'Orders', ['required', 'integer', 'min:1', 'max:10']],
    'orders.discount_limit' => ['decimal', 200, 'Largest discount (৳) without manager approval', 'Orders', ['required', 'numeric', 'min:0', 'max:1000000']],
    'verification.rerun_on_edit' => ['bool', true, 'Re-run verification when phone or amount changes', 'Orders', ['boolean']],
];
