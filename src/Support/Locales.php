<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Closure;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Traits\Localizable;

/**
 * Runs a closure in a recipient's preferred locale (HasLocalePreference), else the current one.
 *
 * @internal
 */
final class Locales
{
    use Localizable;

    public static function preferred(object $recipient): ?string
    {
        if (! $recipient instanceof HasLocalePreference) {
            return null;
        }

        $locale = $recipient->preferredLocale();

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function for(object $recipient, Closure $callback): mixed
    {
        return (new self)->withLocale(self::preferred($recipient), $callback);
    }
}
