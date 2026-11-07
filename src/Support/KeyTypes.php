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
            KeyType::BigInt => is_int($key) || (is_string($key) && preg_match('/^\d{1,19}$/', $key) === 1),
            KeyType::Uuid => is_string($key) && Str::isUuid($key),
            KeyType::Ulid => is_string($key) && Str::isUlid($key),
        };

        if (! $fits) {
            throw InvalidNotificationsConfigurationException::keyTypeMismatch(NotificationsConfig::keyTypeKeyFor($role), class_basename($model), $type->value);
        }
    }
}
