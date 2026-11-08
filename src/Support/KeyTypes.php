<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationsConfigurationException;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Fail fast before any write: a model whose key cannot be stored in the configured morph column
 * throws naming the config key and the model's basename, instead of a raw SQL error.
 *
 * @internal
 */
final class KeyTypes
{
    /**
     * @param  'recipient'|'actor'|'subject'|'context'  $role
     */
    public static function assert(?Model $model, string $role): void
    {
        if ($model === null) {
            return;
        }

        $type = match ($role) {
            'subject' => NotificationsConfig::subjectKeyType(),
            'context' => NotificationsConfig::contextKeyType(),
            default => NotificationsConfig::keyType(),
        };

        $key = $model->getKey();

        $fits = match ($type) {
            KeyType::BigInt => is_int($key) || (is_string($key) && self::isBigInt($key)),
            KeyType::Uuid => is_string($key) && Str::isUuid($key),
            KeyType::Ulid => is_string($key) && Str::isUlid($key),
        };

        if (! $fits) {
            throw InvalidNotificationsConfigurationException::keyTypeMismatch(NotificationsConfig::keyTypeKeyFor($role), class_basename($model), $type->value);
        }
    }

    /**
     * A positive integer written plainly (no sign, no leading zero) that fits a bigint — anything
     * else, `9999999999999999999` included, is no id (PostgreSQL would raise out of range).
     */
    public static function isBigInt(string $value): bool
    {
        return preg_match('/^[1-9]\d{0,18}$/', $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }
}
