<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Notifications\Tests\TestCase;

/**
 * ULIDs everywhere: the host's recipients and subjects (`key_type`, `options.key_type`) and the
 * package's own ids.
 */
abstract class UlidHostKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return [
            'notifications.key_type' => 'ulid',
            'notifications.primary_key_type' => 'ulid',
            'options.key_type' => 'ulid',
        ] + parent::configBeforeBoot();
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [...parent::migrationSources(), __DIR__.'/ulid-migrations'];
    }
}
