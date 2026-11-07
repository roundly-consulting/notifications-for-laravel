<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Every user-facing line the package writes comes from its `notifications::` translations, so a
 * message reads in the host's locale (en and sk ship).
 *
 * @internal
 */
final class Messages
{
    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $line = Lang::get('notifications::'.$key, $replace, $locale);

        return is_string($line) ? $line : $key;
    }

    /**
     * The app name for `:app` placeholders.
     */
    public static function appName(): string
    {
        $name = config('app.name');

        return is_string($name) && $name !== '' ? $name : 'Laravel';
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    public static function choice(string $key, int $count, array $replace = [], ?string $locale = null): string
    {
        return Lang::choice('notifications::'.$key, $count, $replace, $locale);
    }
}
