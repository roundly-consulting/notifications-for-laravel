<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Exceptions;

use RoundlyConsulting\PackageToolkit\Exceptions\PackageToolkitException;
use SensitiveParameter;

/**
 * A configuration value the package cannot use: a malformed duration, time or table name, or a
 * model whose key does not match the configured key type. Operator-facing, so English, and always
 * naming the config key to fix. (The toolkit's InvalidConfigurationException is final, so this
 * extends its base, like events-for-laravel.)
 */
final class InvalidNotificationsConfigurationException extends PackageToolkitException
{
    public static function invalidDuration(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be an ISO-8601 duration such as PT5M, [%s] given.', $key, self::describe($value)));
    }

    public static function durationOutOfRange(string $key, string $range, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a duration %s, [%s] given.', $key, $range, self::describe($value)));
    }

    public static function invalidTime(string $key, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a time of day such as 08:00, [%s] given.', $key, self::describe($value)));
    }

    public static function invalidQuietHours(string $key): self
    {
        return new self(sprintf('Configuration value [%s] must be null or a start and an end time such as [start => 22:00, end => 07:00] that differ.', $key));
    }

    public static function invalidTable(string $key, int $max, #[SensitiveParameter] mixed $value): self
    {
        return new self(sprintf('Configuration value [%s] must be a table name of lowercase letters, digits and underscores, at most %d characters (MySQL limits index names to 64), [%s] given.', $key, $max, self::describe($value)));
    }

    public static function invalidMap(string $key, string $expected): self
    {
        return new self(sprintf('Configuration value [%s] must be %s.', $key, $expected));
    }

    /**
     * A model whose key cannot be stored in the configured morph columns: caught before SQL, so
     * PostgreSQL never answers with a raw "invalid input syntax for type bigint".
     */
    public static function keyTypeMismatch(string $key, string $model, string $type): self
    {
        return new self(sprintf('The %s key does not match configuration value [%s] (%s). Set [%s] to the key type of %s.', $model, $key, $type, $key, $model));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }
}
