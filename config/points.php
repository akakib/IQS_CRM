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
        'order_delivered' => ['Order delivered', 'Delivery', ['channel', 'saved', 'risky']],
        'order_partial' => ['Partial delivery', 'Delivery', ['channel', 'blame', 'risky']],
        'order_returned' => ['Order returned', 'Delivery', ['channel', 'blame', 'risky']],
        'order_cancelled' => ['Order cancelled', 'Confirmation', ['channel', 'blame', 'shipped', 'risky', 'advance_asked']],
        'timer_missed' => ['Action timer missed (order went back to New)', 'Quality', ['releases_today']],
        'order_packed' => ['Parcel packed', 'Packing', ['minutes_since_release']],
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
        'shipped' => 'Already handed to the courier (true/false)',
        'releases_today' => 'How many orders this person let time out today (including this one)',
        'risky' => 'Risky order: not verified by the rules (true/false)',
        'by_rule' => 'Confirmed automatically by a rule (true/false)',
        'blame' => 'Whose fault (none, sales, packing, courier, customer…)',
        'advance_asked' => 'Advance was asked before cancelling (true/false)',
        'after_pack' => 'Changed after packing (true/false)',
    ],
];
