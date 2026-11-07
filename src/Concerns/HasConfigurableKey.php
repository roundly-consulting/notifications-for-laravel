<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Drives a package model's primary key from `notifications.primary_key_type`, matching the column
 * the migrations emitted. On `bigint` the model behaves as if it never used the trait. (A local
 * copy of events-for-laravel's concern.)
 */
trait HasConfigurableKey
{
    use HasUniqueStringIds;

    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = $this->configuredKeyType() !== KeyType::BigInt;
    }

    /**
     * A time-ordered key of the configured type (`uuid7` or `ulid`).
     */
    public function newUniqueId(): string
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? (string) Str::ulid()
            : (string) Str::uuid7();
    }

    public function configuredKeyType(): KeyType
    {
        return KeyType::fromConfig('notifications.primary_key_type');
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? Str::isUlid($value)
            : Str::isUuid($value);
    }
}
