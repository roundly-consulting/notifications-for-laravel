<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Options;

use RoundlyConsulting\Options\BaseOption;

/**
 * Per-type and per-category channel overrides of one user (only what differs from the type defaults). Stored through options-for-laravel under its own key, so it never collides with a
 * host option of the same basename.
 */
final class ChannelPreferences extends BaseOption
{
    public function key(): string
    {
        return 'notifications.channels';
    }

    public function castAs(): string
    {
        return 'array';
    }

    public function default(): mixed
    {
        return [];
    }
}
