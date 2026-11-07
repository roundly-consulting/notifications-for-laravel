<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\Notifications\DataTransferObjects\DigestPreferenceData;
use RoundlyConsulting\Notifications\DataTransferObjects\QuietHours;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationsConfigurationException;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Options\DigestPreference;
use RoundlyConsulting\Notifications\Options\QuietHoursPreference;
use RoundlyConsulting\Notifications\Options\TimezonePreference;
use RoundlyConsulting\Options\BaseOption;
use RoundlyConsulting\Options\OptionsManager;
use Throwable;

/**
 * Reads and writes a user's preferences through options-for-laravel. Only overrides are stored,
 * so a changed type default reaches every user who never touched it. Reads and writes run without
 * options' authorization: the package API is the boundary (the HTTP layer only ever acts on the
 * authenticated user).
 *
 * @internal
 */
final class Preferences
{
    /** @var array<string, Lock> preference locks this process holds until a commit */
    private static array $held = [];

    /**
     * The stored override map: `{types: {key: {channel: bool}}, categories: {cat: {channel: bool}}}`.
     *
     * @return array{types: array<string, array<string, bool>>, categories: array<string, array<string, bool>>}
     */
    public static function channelMap(Model $user): array
    {
        return self::normaliseMap(self::read(ChannelPreferences::class, $user));
    }

    /**
     * @param  array<array-key, mixed>|null  $map  a stored map (read once per send)
     */
    public static function enabled(NotificationType $type, Channel $channel, Model $user, ?array $map = null): bool
    {
        if (! in_array($channel, $type->channels(), true)) {
            return false;
        }

        if (self::locked($type, $channel)) {
            return true;
        }

        $map = $map === null ? self::channelMap($user) : self::normaliseMap($map);

        return $map['types'][$type->key()][$channel->value]
            ?? $map['categories'][$type->category()][$channel->value]
            ?? $type->enabledByDefault($channel);
    }

    /**
     * The channels this user receives the type on.
     *
     * @return list<Channel>
     */
    public static function channels(NotificationType $type, Model $user): array
    {
        $map = self::channelMap($user);

        return array_values(array_filter(
            $type->channels(),
            static fn (Channel $channel): bool => self::enabled($type, $channel, $user, $map),
        ));
    }

    /**
     * Mandatory types, and the inbox unless inbox muting is on, cannot be switched off.
     */
    public static function locked(NotificationType $type, Channel $channel): bool
    {
        return $type->mandatory() || ($channel === Channel::Inbox && ! NotificationsConfig::inboxMutable());
    }

    public static function digest(Model $user): DigestPreferenceData
    {
        $stored = self::read(DigestPreference::class, $user);

        if (is_array($stored) && is_string($stored['frequency'] ?? null)) {
            $frequency = DigestFrequency::tryFrom($stored['frequency']);
            $time = $stored['time'] ?? null;

            if ($frequency !== null) {
                return new DigestPreferenceData($frequency, is_string($time) && NotificationsConfig::isTime($time) ? $time : null);
            }
        }

        return new DigestPreferenceData(NotificationsConfig::digestDefault());
    }

    /**
     * The user's quiet hours, else the configured default; null when quiet hours are off.
     */
    public static function quietHours(Model $user): ?QuietHours
    {
        if (! NotificationsConfig::quietHoursEnabled()) {
            return null;
        }

        return self::storedQuietHours($user) ?? NotificationsConfig::defaultQuietHours();
    }

    public static function storedQuietHours(Model $user): ?QuietHours
    {
        $stored = self::read(QuietHoursPreference::class, $user);

        if (is_array($stored) && is_string($stored['start'] ?? null) && is_string($stored['end'] ?? null)
            && NotificationsConfig::isTime($stored['start']) && NotificationsConfig::isTime($stored['end'])
            && $stored['start'] !== $stored['end']) {
            return new QuietHours($stored['start'], $stored['end']);
        }

        return null;
    }

    public static function timezone(Model $user): ?string
    {
        $stored = self::read(TimezonePreference::class, $user);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * Read-modify-write one option under a lock held around the whole transaction (lock →
     * transaction {read, write, follow-up} → commit → release), so two concurrent writes of one
     * user's map both survive. Inside a host transaction the release waits for its commit.
     *
     * @template TResult
     *
     * @param  class-string<BaseOption>  $option
     * @param  Closure(mixed): array{0: mixed, 1: TResult}  $mutate  returns [new value (null forgets), result]
     * @param  (Closure(): void)|null  $after  runs inside the transaction after the write
     * @return TResult
     */
    public static function write(string $option, Model $user, Closure $mutate, ?Closure $after = null): mixed
    {
        $store = Cache::store(NotificationsConfig::lockStore())->getStore();

        if (! $store instanceof LockProvider) {
            throw InvalidNotificationsConfigurationException::invalidMap('notifications.preferences.lock_store', 'a cache store that supports atomic locks');
        }

        $name = 'notifications:prefs:'.Keys::digest(ModelRefs::identity($user));
        $connection = (new (NotificationModels::inboxItem()))->getConnection();

        // Re-entrant within this process: a second write for the same user inside one host
        // transaction already holds the lock (released after that transaction commits).
        if (isset(self::$held[$name])) {
            if ($connection->transactionLevel() > 0) {
                return $connection->transaction(static fn (): mixed => self::apply($option, $user, $mutate, $after));
            }

            // The host transaction that held it rolled back: its release never ran.
            self::$held[$name]->release();
            unset(self::$held[$name]);
        }

        $lock = $store->lock($name, NotificationsConfig::lockSeconds());
        $lock->block(min(NotificationsConfig::lockSeconds(), 5));
        self::$held[$name] = $lock;

        $release = static function () use ($lock, $name): void {
            unset(self::$held[$name]);
            $lock->release();
        };

        try {
            $result = $connection->transaction(static fn (): mixed => self::apply($option, $user, $mutate, $after));
        } catch (Throwable $exception) {
            $release();

            throw $exception;
        }

        $connection->afterCommit($release);

        return $result;
    }

    /**
     * @template TResult
     *
     * @param  class-string<BaseOption>  $option
     * @param  Closure(mixed): array{0: mixed, 1: TResult}  $mutate
     * @param  (Closure(): void)|null  $after
     * @return TResult
     */
    private static function apply(string $option, Model $user, Closure $mutate, ?Closure $after): mixed
    {
        // Drop this request's memo so a value read earlier is never the base of the write.
        $option::for($user)->forgetCachedValue();

        [$value, $result] = $mutate(self::read($option, $user));

        self::options()->withoutAuthorization(static function () use ($option, $user, $value): void {
            $value === null
                ? self::options()->forget($option, $user)
                : self::options()->set($option, $value, $user);
        });

        if ($after !== null) {
            $after();
        }

        return $result;
    }

    /**
     * @param  class-string<BaseOption>  $option
     */
    public static function read(string $option, Model $user): mixed
    {
        return self::options()->withoutAuthorization(static fn (): mixed => self::options()->get($option, $user));
    }

    /**
     * @return array{types: array<string, array<string, bool>>, categories: array<string, array<string, bool>>}
     */
    public static function normaliseMap(mixed $map): array
    {
        $result = ['types' => [], 'categories' => []];

        if (! is_array($map)) {
            return $result;
        }

        foreach (['types', 'categories'] as $scope) {
            $entries = $map[$scope] ?? [];

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $key => $channels) {
                if (! is_string($key) || ! is_array($channels)) {
                    continue;
                }

                foreach ($channels as $channel => $enabled) {
                    if (is_string($channel) && Channel::tryFrom($channel) !== null && is_bool($enabled)) {
                        $result[$scope][$key][$channel] = $enabled;
                    }
                }
            }
        }

        return $result;
    }

    private static function options(): OptionsManager
    {
        return app(OptionsManager::class);
    }
}
