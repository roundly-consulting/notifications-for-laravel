<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Options;

use RoundlyConsulting\Options\BaseOption;

/**
 * A user's digest frequency and optional local time. Stored through options-for-laravel under its own key, so it never collides with a
 * host option of the same basename.
 */
final class DigestPreference extends BaseOption
{
    public function key(): string
    {
        return 'notifications.digest';
    }

    public function castAs(): string
    {
        return 'array';
    }

    public function default(): mixed
    {
        return null;
    }
}
