<?php

/*
| Point triggers: the events the system can recognise (fixed in code).
| What each is worth, for whom and under which conditions is set by the
| admin in Settings > Points (point_rules). key => [label, stage, condition fields]
*/

return [
    'triggers' => [
        'order_claimed' => ['Took an order (Assign to me)', 'Intake', ['minutes_waiting']],
        'order_confirmed' => ['Order confirmed', 'Confirmation', ['minutes_since_claim', 'risky', 'by_rule']],
        'order_delivered' => ['Order delivered', 'Delivery', ['channel', 'saved', 'repeat_customer', 'own_entry', 'days_since_last_order', 'risky']],
        'order_partial' => ['Partial delivery', 'Delivery', ['channel', 'blame', 'risky']],
        'order_returned' => ['Order returned', 'Delivery', ['channel', 'blame', 'risky']],
        'order_cancelled' => ['Order cancelled', 'Confirmation', ['channel', 'blame', 'shipped', 'risky', 'advance_asked']],
        'timer_extended' => ['Took extra time on the action timer', 'Quality', ['extensions_today']],
        'timer_beaten' => ['Finished an order within the time limit', 'Quality', []],
        'timer_missed' => ['Went over the time limit on an order (it stays with them)', 'Quality', ['releases_today']],
        'order_packed' => ['Parcel packed', 'Packaging', ['minutes_since_release']],
        'amendment' => ['Order changed after confirming', 'Quality', ['blame', 'after_pack']],
        'order_reassigned' => ['Order moved to someone else', 'Quality', ['blame']],
        'issue_escalated' => ['Delivery issue not handled in time', 'Quality', []],
        'fake_status' => ['Fake status (confirmed by a manager)', 'Quality', []],
    ],

    // Condition fields shown in the rule editor.
    'fields' => [
        'minutes_waiting' => 'Minutes the order waited before being taken',
        'minutes_since_claim' => 'Minutes from taking to confirming',
        'minutes_since_release' => 'Minutes from batch release to packed',
        'channel' => 'Order channel (web, messenger, whatsapp, phone)',
        'saved' => 'Saved order: had No response or Hold, then delivered (true/false)',
        'repeat_customer' => 'Repeat customer: had a delivered order before this one (true/false)',
        'own_entry' => 'The moderator entered the order (chat or phone, not from the website) (true/false)',
        'days_since_last_order' => 'Days since the customer\'s previous order',
        'shipped' => 'Already handed to the courier (true/false)',
        'extensions_today' => 'How many times this person took extra time today (including this one)',
        'releases_today' => 'How many orders this person went over the time limit on today (including this one)',
        'risky' => 'Risky order: not verified by the rules (true/false)',
        'by_rule' => 'Confirmed automatically by a rule (true/false)',
        'blame' => 'Whose fault (none, sales, packaging, courier, customer…)',
        'advance_asked' => 'Advance was asked before cancelling (true/false)',
        'after_pack' => 'Changed after packaging (true/false)',
    ],
];
