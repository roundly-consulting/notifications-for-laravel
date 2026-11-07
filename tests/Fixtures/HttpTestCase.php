<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures;

use RoundlyConsulting\Notifications\Tests\TestCase;

/**
 * The opt-in JSON API: its routes load at boot, so they are switched on before it.
 */
abstract class HttpTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return parent::configBeforeBoot() + [
            'notifications.routes.enabled' => 'on',
            'notifications.routes.middleware' => [],
        ];
    }
}
