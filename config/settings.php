<?php

/*
| Every global setting: key => [type, default, label, group, rules].
| Values live in the `settings` table; a key missing there uses the default.
| Read anywhere with settings('orders.packaging_cost').
*/

return [
    'store.name' => ['string', 'Iqbal Store', 'Store name', 'Store', ['required', 'string', 'max:100']],
    'store.hotline' => ['string', '', 'Rider hotline number', 'Store', ['nullable', 'regex:/^01[3-9]\d{8}$/']],
    'appearance.primary_color' => ['string', '#0d542b', 'Main colour (buttons, links, the current page in the menu)', 'Appearance', ['required', 'regex:/^#[0-9a-fA-F]{6}$/']],
    'appearance.font' => ['string', 'Figtree', 'Font', 'Appearance', ['required', 'string', 'max:40']],
    'store.logo' => ['string', '', 'Logo', 'Store', ['nullable', 'image', 'max:1024']],

    'orders.pickup_cutoffs' => ['json', ['15:00'], 'Courier pickup cut-off times', 'Orders', ['array', 'min:1', 'max:6']],
    'orders.packaging_cost' => ['decimal', 0, 'Packaging cost per order (৳)', 'Orders', ['required', 'numeric', 'min:0', 'max:100000']],
    'orders.duplicate_window_hours' => ['int', 24, 'Same phone counts as duplicate within (hours)', 'Orders', ['required', 'integer', 'min:0', 'max:720']],
    'orders.freeze_minutes_before_pickup' => ['int', 30, 'Freeze content edits before pickup (minutes)', 'Orders', ['required', 'integer', 'min:0', 'max:600']],
    'orders.issue_sla_minutes' => ['int', 60, 'Minutes the assigned moderator has to handle a delivery issue', 'Orders', ['required', 'integer', 'min:5', 'max:1440']],
    'orders.discount_limit' => ['decimal', 200, 'Largest discount (৳) without manager approval', 'Orders', ['required', 'numeric', 'min:0', 'max:1000000']],
    'tracking.web_event_id' => ['string', 'wc_purchase_{external_ref}', 'Purchase event ID used by the website pixel ({external_ref} = website order id)', 'Tracking', ['required', 'string', 'max:100']],
    'verification.rerun_on_edit' => ['bool', true, 'Re-run verification when phone or amount changes', 'Orders', ['boolean']],
    'points.trial_mode' => ['bool', true, 'Trial month: points are recorded and shown, but not used for bonus yet', 'Points', ['boolean']],
    'points.min_confirm_minutes' => ['decimal', 1, 'Flag a web order confirmed faster than this after taking it (minutes)', 'Points', ['required', 'numeric', 'min:0', 'max:60']],
    'points.monthly_negative_cap' => ['int', 50, 'Most minus points one person can lose in a month (0 = no limit)', 'Points', ['required', 'integer', 'min:0', 'max:100000']],
    'points.dispute_days' => ['int', 3, 'Days a person has to dispute a point', 'Points', ['required', 'integer', 'min:0', 'max:60']],
    'desk.active_limit' => ['int', 1, 'Website orders one moderator can hold at a time (waiting to verify or call). 1 = finish one before taking the next', 'Order desk', ['required', 'integer', 'min:1', 'max:50']],
    'desk.action_timer_minutes' => ['int', 10, 'Minutes to act on an order before it goes back to New', 'Order desk', ['required', 'integer', 'min:1', 'max:240']],
    'desk.untouched_start_minutes' => ['int', 15, 'An order its moderator never opens starts its timer by itself after (minutes)', 'Order desk', ['required', 'integer', 'min:1', 'max:240']],
    'desk.extend_minutes' => ['int', 5, 'Extra minutes a moderator can add to the timer, once per order', 'Order desk', ['required', 'integer', 'min:1', 'max:60']],
    'desk.extend_daily_limit' => ['int', 5, 'Times a day one moderator can add extra time (0 = not allowed)', 'Order desk', ['required', 'integer', 'min:0', 'max:100']],
    'desk.auto_assign_minutes' => ['int', 15, 'Give an order nobody took to the least busy active moderator after (minutes)', 'Order desk', ['required', 'integer', 'min:1', 'max:1440']],
    'desk.active_window_minutes' => ['int', 10, 'A moderator counts as active when they used the system in the last (minutes)', 'Order desk', ['required', 'integer', 'min:1', 'max:120']],
    'desk.no_response_returns' => ['string', '30,300,1440', 'No response: minutes until the order comes back, one per try (after the last one it is cancelled)', 'Order desk', ['required', 'regex:/^\d+(,\d+){0,5}$/']],
    'desk.voice_alerts' => ['bool', true, 'Spoken alerts for moderators: new order, timer started, call again, two minutes left (each person can mute)', 'Order desk', ['boolean']],
    'desk.sms_after_no_response' => ['bool', false, 'After the first No response, message the customer to call back (needs an SMS gateway)', 'Order desk', ['boolean']],
    'work.start' => ['string', '09:00', 'Office opens (HH:MM)', 'Working hours', ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']],
    'work.end' => ['string', '22:00', 'Office closes (HH:MM)', 'Working hours', ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']],
    'work.days' => ['string', '0,1,2,3,4,6', 'Office days (0 = Sunday ... 6 = Saturday). Each person can have their own days on their staff page', 'Working hours', ['required', 'regex:/^[0-6](,[0-6]){0,6}$/']],
    'work.break_limit_minutes' => ['int', 60, 'Break minutes allowed per day (more shows in red)', 'Working hours', ['required', 'integer', 'min:0', 'max:600']],
    'telegram.digest_minutes' => ['int', 30, 'Packaging digest to the shop Telegram group every (minutes)', 'Reports', ['required', 'integer', 'min:5', 'max:720']],
    'kpi.show_delivered_amount' => ['bool', false, 'Show delivered money amounts on the KPI page', 'KPI', ['boolean']],
    'analysis.cod_fee_percent' => ['decimal', 1, 'Courier COD fee on cash collected (%)', 'Reports', ['required', 'numeric', 'min:0', 'max:10']],
    'marketing.fallback_rate' => ['decimal', 125, 'BDT per USD for ad spend not covered by any dollar lot', 'Reports', ['required', 'numeric', 'min:1', 'max:1000']],
    'reports.owner_summary_time' => ['string', '22:00', 'Time of the nightly owner summary on Telegram (HH:MM)', 'Reports', ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']],
];
