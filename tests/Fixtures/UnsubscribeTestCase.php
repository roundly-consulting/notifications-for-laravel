<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures;

use RoundlyConsulting\Notifications\Tests\TestCase;

/**
 * One-click unsubscribe on: its routes load at boot, so the switch is set before it, with an
 * https app URL (RFC 8058 needs one for the one-click header).
 */
abstract class UnsubscribeTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return parent::configBeforeBoot() + [
            'app.url' => 'https://app.test',
            'notifications.mail.list_unsubscribe.enabled' => 'on',
        ];
    }
}
