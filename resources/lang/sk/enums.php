<?php

declare(strict_types=1);

return [
    'channel' => [
        'inbox' => 'V aplikácii',
        'mail' => 'E-mail',
    ],
    'priority' => [
        'low' => 'Nízka',
        'normal' => 'Bežná',
        'urgent' => 'Naliehavá',
    ],
    'digest_frequency' => [
        'off' => 'Vypnutý',
        'daily' => 'Denne',
        'weekly' => 'Týždenne',
    ],
    'mail_strategy' => [
        'leading' => 'Odoslať hneď',
        'trailing' => 'Odoslať po uplynutí okna',
    ],
    'delivery_status' => [
        'pending' => 'Čaká',
        'held' => 'Pozdržané pre tiché hodiny',
        'digest' => 'Čaká na prehľad',
        'sending' => 'Odosiela sa',
        'sent' => 'Odoslané',
        'cancelled' => 'Zrušené',
        'failed' => 'Zlyhalo',
        'undeliverable' => 'Nedoručiteľné',
    ],
    'reminder_status' => [
        'pending' => 'Čaká',
        'firing' => 'Odosiela sa',
        'sent' => 'Odoslané',
        'cancelled' => 'Zrušené',
        'skipped' => 'Vynechané',
    ],
];
