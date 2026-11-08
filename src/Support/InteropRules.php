<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Exceptions\InvalidInteropMappingException;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * The interop map and suppress list: `notifications.interop` plus runtime additions. Matching
 * uses `is_a()`, so a host subclass of a mapped class is mapped; an exact entry beats an ancestor,
 * and the most specific ancestor wins.
 *
 * Time-critical classes (one-time codes, magic links, password resets) may only map to an urgent
 * type without a window that is not digestible — otherwise a second code asked for within the
 * window would be folded into the first and never arrive.
 *
 * @internal
 */
final class InteropRules
{
    /**
     * Notification classes that must never be held, folded or digested.
     */
    public const array TIME_CRITICAL = [
        'RoundlyConsulting\Auth\Notifications\AuthenticationNotification',
        'Illuminate\Auth\Notifications\ResetPassword',
        'Illuminate\Auth\Notifications\VerifyEmail',
    ];

    /** @var array<string, string> */
    private array $map = [];

    /** @var array<string, list<string>|null> */
    private array $suppress = [];

    private bool $validated = false;

    public function map(string $notification, string $type): void
    {
        $this->map[ltrim($notification, '\\')] = $type;
        $this->validated = false;
    }

    /**
     * @param  list<string>|null  $channels
     */
    public function suppress(string $notification, ?array $channels = null): void
    {
        $this->suppress[ltrim($notification, '\\')] = $channels;
    }

    /**
     * @return array<string, string>
     */
    public function mapped(): array
    {
        return array_merge(NotificationsConfig::interopMap(), $this->map);
    }

    /**
     * @return array<string, list<string>|null>
     */
    public function suppressed(): array
    {
        return array_merge(NotificationsConfig::interopSuppress(), $this->suppress);
    }

    public function active(): bool
    {
        return $this->mapped() !== [] || $this->suppressed() !== [];
    }

    /**
     * The type key a notification maps to (most specific listed class), or null.
     */
    public function typeFor(object $notification): ?string
    {
        $class = $this->bestMatch($notification, array_keys($this->mapped()));

        return $class === null ? null : $this->mapped()[$class];
    }

    public function suppresses(object $notification, string $channel): bool
    {
        $suppressed = $this->suppressed();
        $class = $this->bestMatch($notification, array_keys($suppressed));

        if ($class === null) {
            return false;
        }

        $channels = $suppressed[$class];

        return $channels === null || in_array($channel, $channels, true);
    }

    /**
     * Checks every mapping against the registry — run once the host's providers have booted (a
     * type `define()`d in AppServiceProvider::boot() does not exist in this package's boot()),
     * and again lazily on first use.
     */
    public function validate(TypeRegistry $types, bool $force = false): void
    {
        if ($this->validated && ! $force) {
            return;
        }

        foreach ($this->mapped() as $notification => $key) {
            $type = $types->get($key);

            if ($type === null) {
                throw InvalidInteropMappingException::unknownType(class_basename($notification), $key);
            }

            if (self::timeCritical($notification) && ! self::fitsTimeCritical($type)) {
                throw InvalidInteropMappingException::timeCritical(class_basename($notification), $key);
            }
        }

        $this->validated = true;
    }

    public static function timeCritical(string $notification): bool
    {
        foreach (self::TIME_CRITICAL as $class) {
            if (strcasecmp(ltrim($notification, '\\'), $class) === 0 || is_a($notification, $class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a time-critical notification can match a mapping of this class (it is one, or an
     * ancestor of one): matching follows subclasses.
     */
    public static function coversTimeCritical(string $mapped): bool
    {
        if (self::timeCritical($mapped)) {
            return true;
        }

        foreach (self::TIME_CRITICAL as $class) {
            if (class_exists($class) && is_a($class, ltrim($mapped, '\\'), true)) {
                return true;
            }
        }

        return false;
    }

    public static function fitsTimeCritical(NotificationType $type): bool
    {
        return $type->priority() === Priority::Urgent && Windows::of($type) === null && ! $type->digestible();
    }

    public function flush(): void
    {
        $this->map = [];
        $this->suppress = [];
        $this->validated = false;
    }

    /**
     * @param  list<string>  $classes
     */
    private function bestMatch(object $notification, array $classes): ?string
    {
        $best = null;

        foreach ($classes as $class) {
            if ($notification::class === $class) {
                return $class;
            }

            if (! is_a($notification, $class)) {
                continue;
            }

            // The most specific ancestor: one that is itself a subclass of the current best.
            if ($best === null || is_subclass_of($class, $best)) {
                $best = $class;
            }
        }

        return $best;
    }
}
