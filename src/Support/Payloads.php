<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationDataException;

/**
 * Notification data is plain JSON: scalars, nulls and arrays of them, under a byte cap — never a
 * model or an object.
 *
 * @internal
 */
final class Payloads
{
    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    public static function assertJson(array $data): array
    {
        self::walk($data, 'data');

        $encoded = json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw InvalidNotificationDataException::notJson('data');
        }

        $max = NotificationsConfig::maxDataBytes();

        if (strlen($encoded) > $max) {
            throw InvalidNotificationDataException::tooLarge(strlen($encoded), $max);
        }

        $normalised = [];

        foreach ($data as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function walk(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            $here = $path.'.'.$key;

            if (is_array($value)) {
                self::walk($value, $here);

                continue;
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidNotificationDataException::notJson($here);
            }

            if (is_float($value) && ! is_finite($value)) {
                throw InvalidNotificationDataException::notJson($here);
            }
        }
    }
}
