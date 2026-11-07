<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures;

use RoundlyConsulting\Notifications\Tests\TestCase;

/**
 * The package's own ids as uuid (the migrations read the key type, so it is set before boot).
 */
abstract class UuidKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return ['notifications.primary_key_type' => 'uuid'] + parent::configBeforeBoot();
    }
}
