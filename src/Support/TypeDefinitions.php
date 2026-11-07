<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\DataTransferObjects\CategoryDefinition;
use RoundlyConsulting\Notifications\DataTransferObjects\TypeDefinition;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * A type as a preferences UI reads it, translated in the current locale.
 *
 * @internal
 */
final class TypeDefinitions
{
    public static function of(NotificationType $type): TypeDefinition
    {
        $channels = $type->channels();

        return new TypeDefinition(
            key: $type->key(),
            category: $type->category(),
            label: $type->label(),
            description: $type->description(),
            channels: $channels,
            defaultChannels: array_values(array_filter($channels, static fn (Channel $channel): bool => $type->enabledByDefault($channel))),
            mandatory: $type->mandatory(),
            priority: $type->priority(),
            digestible: $type->digestible(),
        );
    }

    /**
     * The `notifications.categories.<key>` line of the host's translations, else the package's
     * own fallback, else the key in headline case.
     */
    public static function category(string $key): CategoryDefinition
    {
        $line = 'notifications.categories.'.$key;

        if (Lang::has($line) && is_string($label = Lang::get($line))) {
            return new CategoryDefinition($key, $label);
        }

        $fallback = 'notifications::preferences.categories.'.$key;

        if (Lang::has($fallback) && is_string($label = Lang::get($fallback))) {
            return new CategoryDefinition($key, $label);
        }

        return new CategoryDefinition($key, Str::headline(str_replace('.', ' ', $key)));
    }
}
