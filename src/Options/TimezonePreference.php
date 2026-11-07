<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Options;

use RoundlyConsulting\Options\BaseOption;

/**
 * A user's IANA timezone for quiet hours and digest slots. Stored through options-for-laravel
 * under its own key, so it never collides with a host option of the same basename.
 */
final class TimezonePreference extends BaseOption
{
    public function key(): string
    {
        return 'notifications.timezone';
    }

    public function castAs(): string
    {
        return 'string';
    }

    public function rules(): array
    {
        return ['nullable', 'string', 'timezone:all'];
    }
}
