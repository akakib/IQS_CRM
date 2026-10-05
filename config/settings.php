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
    'orders.issue_sla_minutes' => ['int', 60, 'Minutes the order owner has to handle a delivery issue', 'Orders', ['required', 'integer', 'min:5', 'max:1440']],
    'orders.discount_limit' => ['decimal', 200, 'Largest discount (৳) without manager approval', 'Orders', ['required', 'numeric', 'min:0', 'max:1000000']],
    'tracking.web_event_id' => ['string', 'wc_purchase_{external_ref}', 'Purchase event ID used by the website pixel ({external_ref} = website order id)', 'Tracking', ['required', 'string', 'max:100']],
    'verification.rerun_on_edit' => ['bool', true, 'Re-run verification when phone or amount changes', 'Orders', ['boolean']],
    'points.trial_mode' => ['bool', true, 'Trial month: points are recorded and shown, but not used for bonus yet', 'Points', ['boolean']],
    'points.min_confirm_minutes' => ['decimal', 1, 'Flag a web order confirmed faster than this after taking it (minutes)', 'Points', ['required', 'numeric', 'min:0', 'max:60']],
    'points.monthly_negative_cap' => ['int', 50, 'Most minus points one person can lose in a month (0 = no limit)', 'Points', ['required', 'integer', 'min:0', 'max:100000']],
    'points.dispute_days' => ['int', 3, 'Days a person has to dispute a point', 'Points', ['required', 'integer', 'min:0', 'max:60']],
    'kpi.weight_volume' => ['int', 40, 'KPI weight: order volume (%)', 'KPI', ['required', 'integer', 'min:0', 'max:100']],
    'kpi.weight_speed' => ['int', 20, 'KPI weight: speed (%)', 'KPI', ['required', 'integer', 'min:0', 'max:100']],
    'kpi.weight_quality' => ['int', 40, 'KPI weight: quality, delivered rate (%)', 'KPI', ['required', 'integer', 'min:0', 'max:100']],
    'analysis.cod_fee_percent' => ['decimal', 1, 'Courier COD fee on cash collected (%)', 'Reports', ['required', 'numeric', 'min:0', 'max:10']],
    'reports.owner_summary_time' => ['string', '22:00', 'Time of the nightly owner summary on Telegram (HH:MM)', 'Reports', ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']],
];
