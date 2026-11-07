<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Closure;

/**
 * Lets the release of a held interop mail through the interop listener — the one send the
 * listener must not hold or fold again.
 *
 * @internal
 */
final class InteropGate
{
    private static int $releasing = 0;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $send
     * @return TResult
     */
    public static function releasing(Closure $send): mixed
    {
        self::$releasing++;

        try {
            return $send();
        } finally {
            self::$releasing--;
        }
    }

    public static function isReleasing(): bool
    {
        return self::$releasing > 0;
    }
}
