<?php

declare(strict_types=1);

return [
    'channel' => [
        'inbox' => 'In-app',
        'mail' => 'Email',
    ],
    'priority' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'urgent' => 'Urgent',
    ],
    'digest_frequency' => [
        'off' => 'Off',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
    ],
    'mail_strategy' => [
        'leading' => 'Send at once',
        'trailing' => 'Send after the window',
    ],
    'delivery_status' => [
        'pending' => 'Pending',
        'held' => 'Held for quiet hours',
        'digest' => 'Waiting for the digest',
        'sending' => 'Sending',
        'sent' => 'Sent',
        'cancelled' => 'Cancelled',
        'failed' => 'Failed',
        'undeliverable' => 'Undeliverable',
    ],
    'reminder_status' => [
        'pending' => 'Pending',
        'firing' => 'Firing',
        'sent' => 'Sent',
        'cancelled' => 'Cancelled',
        'skipped' => 'Skipped',
    ],
];
