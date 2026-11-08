<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonInterval;
use Illuminate\Http\Resources\Json\JsonResource;
use ReflectionClass;
use RoundlyConsulting\Notifications\DataTransferObjects\QuietHours;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationsConfigurationException;
use RoundlyConsulting\Notifications\Http\Resources\NotificationActorResource;
use RoundlyConsulting\Notifications\Http\Resources\NotificationContextResource;
use RoundlyConsulting\Notifications\Http\Resources\NotificationSubjectResource;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The only place that reads `notifications.*`. Every reader is strict: a typo throws naming the
 * key, a blank value takes the default.
 *
 * @internal
 */
final class NotificationsConfig
{
    public const array WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    // Key types --------------------------------------------------------------------------

    public static function keyType(): KeyType
    {
        return KeyType::fromConfig('notifications.key_type');
    }

    public static function subjectKeyType(): KeyType
    {
        return self::blank(config('notifications.subject_key_type'))
            ? self::keyType()
            : KeyType::fromConfig('notifications.subject_key_type');
    }

    public static function contextKeyType(): KeyType
    {
        return self::blank(config('notifications.context_key_type'))
            ? self::keyType()
            : KeyType::fromConfig('notifications.context_key_type');
    }

    public static function primaryKeyType(): KeyType
    {
        return KeyType::fromConfig('notifications.primary_key_type');
    }

    /**
     * The config key that governs a morph role's key type.
     */
    public static function keyTypeKeyFor(string $role): string
    {
        return match ($role) {
            'subject' => self::blank(config('notifications.subject_key_type')) ? 'notifications.key_type' : 'notifications.subject_key_type',
            'context' => self::blank(config('notifications.context_key_type')) ? 'notifications.key_type' : 'notifications.context_key_type',
            default => 'notifications.key_type',
        };
    }

    // Tables -----------------------------------------------------------------------------

    public static function table(string $name): string
    {
        [$key, $table, $max] = match ($name) {
            'inbox_items' => ['notifications.tables.inbox_items', Config::string('notifications.tables.inbox_items', 'notification_inbox_items'), 30],
            'deliveries' => ['notifications.tables.deliveries', Config::string('notifications.tables.deliveries', 'notification_deliveries'), 30],
            'reminders' => ['notifications.tables.reminders', Config::string('notifications.tables.reminders', 'notification_reminders'), 24],
            'digest_runs' => ['notifications.tables.digest_runs', Config::string('notifications.tables.digest_runs', 'notification_digest_runs'), 30],
            'receipts' => ['notifications.tables.receipts', Config::string('notifications.tables.receipts', 'notification_receipts'), 30],
            default => throw InvalidNotificationsConfigurationException::invalidTable('notifications.tables.'.$name, 30, $name),
        };

        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1 || strlen($table) > $max) {
            throw InvalidNotificationsConfigurationException::invalidTable($key, $max, $table);
        }

        return $table;
    }

    // Types ------------------------------------------------------------------------------

    /**
     * @return list<class-string<NotificationType>>
     */
    public static function types(): array
    {
        $types = Config::list('notifications.types', []);

        foreach ($types as $type) {
            if (! class_exists($type) || ! is_subclass_of($type, NotificationType::class)) {
                throw InvalidConfigurationException::notAnImplementation('notifications.types', NotificationType::class, $type);
            }
        }

        /** @var list<class-string<NotificationType>> $types */
        return $types;
    }

    // Queue ------------------------------------------------------------------------------

    public static function queueEnabled(): bool
    {
        return Config::boolean('notifications.queue.enabled', true);
    }

    public static function queueConnection(): ?string
    {
        return self::optionalString('notifications.queue.connection', config('notifications.queue.connection'));
    }

    public static function queueName(): ?string
    {
        return self::optionalString('notifications.queue.queue', config('notifications.queue.queue'));
    }

    public static function fanOutAbove(): int
    {
        return Config::integer('notifications.fan_out.queue_above', 50, min: 1);
    }

    /**
     * @return int<1, max>
     */
    public static function fanOutChunk(): int
    {
        return max(1, Config::integer('notifications.fan_out.chunk', 100, min: 1, max: 10_000));
    }

    // Access / recipients -----------------------------------------------------------------

    public static function accessAbility(): string
    {
        return Config::string('notifications.access.ability', 'view');
    }

    public static function idempotencyTtl(): CarbonInterval
    {
        return self::duration('notifications.idempotency.ttl', config('notifications.idempotency.ttl'), 'P30D', minSeconds: 60);
    }

    // Inbox ------------------------------------------------------------------------------

    public static function perPage(): int
    {
        return Config::integer('notifications.inbox.per_page', 20, min: 1, max: self::maxPerPage());
    }

    public static function maxPerPage(): int
    {
        return Config::integer('notifications.inbox.max_per_page', 100, min: 1, max: 1000);
    }

    public static function maxDataBytes(): int
    {
        return Config::integer('notifications.inbox.max_data_bytes', 16384, min: 256, max: 1_048_576);
    }

    public static function recheckOnRead(): bool
    {
        return Config::boolean('notifications.inbox.recheck_on_read', false);
    }

    public static function onSubjectDeleted(): string
    {
        return Config::oneOf('notifications.inbox.on_subject_deleted', ['keep', 'archive', 'delete'], 'keep');
    }

    // Preferences ------------------------------------------------------------------------

    public static function inboxMutable(): bool
    {
        return Config::boolean('notifications.preferences.inbox_mutable', false);
    }

    public static function lockStore(): ?string
    {
        return self::optionalString('notifications.preferences.lock_store', config('notifications.preferences.lock_store'));
    }

    public static function lockSeconds(): int
    {
        return Config::integer('notifications.preferences.lock_seconds', 10, min: 1, max: 300);
    }

    // Mail -------------------------------------------------------------------------------

    public static function mailStrategy(): MailStrategy
    {
        return Config::enum('notifications.mail.strategy', MailStrategy::class, MailStrategy::Leading);
    }

    public static function minimalForSensitive(): bool
    {
        return Config::boolean('notifications.mail.minimal_for_sensitive', true);
    }

    public static function addressResolver(): ?string
    {
        return self::optionalString('notifications.mail.address_resolver', config('notifications.mail.address_resolver'));
    }

    public static function preferencesUrl(): ?string
    {
        return self::optionalString('notifications.mail.preferences_url', config('notifications.mail.preferences_url'));
    }

    public static function maxAttempts(): int
    {
        return Config::integer('notifications.mail.max_attempts', 3, min: 1, max: 20);
    }

    /**
     * @return list<int>
     */
    public static function backoff(): array
    {
        $backoff = config('notifications.mail.backoff');

        if (self::blank($backoff)) {
            return [60, 300, 900];
        }

        if (! is_array($backoff) || ! array_is_list($backoff) || $backoff === []) {
            throw InvalidNotificationsConfigurationException::invalidMap('notifications.mail.backoff', 'a non-empty list of seconds');
        }

        $seconds = [];

        foreach ($backoff as $value) {
            if (! is_int($value) || $value < 1) {
                throw InvalidNotificationsConfigurationException::invalidMap('notifications.mail.backoff', 'a non-empty list of seconds');
            }

            $seconds[] = $value;
        }

        return $seconds;
    }

    public static function mailLease(): CarbonInterval
    {
        return self::duration('notifications.mail.lease', config('notifications.mail.lease'), 'PT10M', minSeconds: 60);
    }

    // Quiet hours / timezone ---------------------------------------------------------------

    public static function quietHoursEnabled(): bool
    {
        return Config::boolean('notifications.quiet_hours.enabled', true);
    }

    public static function defaultQuietHours(): ?QuietHours
    {
        $value = config('notifications.quiet_hours.default');

        if (self::blank($value)) {
            return null;
        }

        if (! is_array($value) || ! is_string($value['start'] ?? null) || ! is_string($value['end'] ?? null)
            || ! self::isTime($value['start']) || ! self::isTime($value['end']) || $value['start'] === $value['end']) {
            throw InvalidNotificationsConfigurationException::invalidQuietHours('notifications.quiet_hours.default');
        }

        return new QuietHours($value['start'], $value['end']);
    }

    public static function timezoneAttribute(): ?string
    {
        return self::optionalString('notifications.timezone.attribute', config('notifications.timezone.attribute'));
    }

    // Digests ----------------------------------------------------------------------------

    public static function digestDefault(): DigestFrequency
    {
        return Config::enum('notifications.digest.default', DigestFrequency::class, DigestFrequency::Off);
    }

    public static function digestTime(): string
    {
        return self::time('notifications.digest.time', config('notifications.digest.time'), '08:00');
    }

    public static function digestWeeklyDay(): string
    {
        return Config::oneOf('notifications.digest.weekly_day', self::WEEKDAYS, 'monday');
    }

    public static function digestMaxItems(): int
    {
        return Config::integer('notifications.digest.max_items', 50, min: 1, max: 1000);
    }

    public static function digestLease(): CarbonInterval
    {
        return self::duration('notifications.digest.lease', config('notifications.digest.lease'), 'PT15M', minSeconds: 60);
    }

    // Reminders --------------------------------------------------------------------------

    public static function maxLateness(): CarbonInterval
    {
        return self::duration('notifications.reminders.max_lateness', config('notifications.reminders.max_lateness'), 'P1D', minSeconds: 60);
    }

    public static function reminderBatch(): int
    {
        return Config::integer('notifications.reminders.batch', 500, min: 1, max: 10_000);
    }

    public static function reminderLease(): CarbonInterval
    {
        return self::duration('notifications.reminders.lease', config('notifications.reminders.lease'), 'PT10M', minSeconds: 60);
    }

    // Interop ----------------------------------------------------------------------------

    /**
     * @return array<string, string> notification class => type key
     */
    public static function interopMap(): array
    {
        $map = config('notifications.interop.map');

        if (self::blank($map)) {
            return [];
        }

        if (! is_array($map)) {
            throw InvalidNotificationsConfigurationException::invalidMap('notifications.interop.map', 'a map of notification class => type key');
        }

        $result = [];

        foreach ($map as $class => $type) {
            if (! is_string($class) || $class === '' || ! is_string($type) || $type === '') {
                throw InvalidNotificationsConfigurationException::invalidMap('notifications.interop.map', 'a map of notification class => type key');
            }

            $result[ltrim($class, '\\')] = $type;
        }

        return $result;
    }

    /**
     * @return array<string, list<string>|null> notification class => channels (null = every channel)
     */
    public static function interopSuppress(): array
    {
        $suppress = config('notifications.interop.suppress');

        if (self::blank($suppress)) {
            return [];
        }

        if (! is_array($suppress)) {
            throw InvalidNotificationsConfigurationException::invalidMap('notifications.interop.suppress', 'a list of notification classes or a map of class => list of channels');
        }

        $result = [];

        foreach ($suppress as $key => $value) {
            if (is_int($key) && is_string($value) && $value !== '') {
                $result[ltrim($value, '\\')] = null;

                continue;
            }

            if (is_string($key) && $key !== '' && is_array($value) && array_is_list($value) && $value !== []) {
                $channels = [];

                foreach ($value as $channel) {
                    if (! is_string($channel) || $channel === '') {
                        throw InvalidNotificationsConfigurationException::invalidMap('notifications.interop.suppress', 'a list of notification classes or a map of class => list of channels');
                    }

                    $channels[] = $channel;
                }

                $result[ltrim($key, '\\')] = $channels;

                continue;
            }

            throw InvalidNotificationsConfigurationException::invalidMap('notifications.interop.suppress', 'a list of notification classes or a map of class => list of channels');
        }

        return $result;
    }

    public static function interopDatabase(): string
    {
        return Config::oneOf('notifications.interop.database', ['redirect', 'keep', 'both'], 'redirect');
    }

    public static function interopHold(): bool
    {
        return Config::boolean('notifications.interop.hold', true);
    }

    /**
     * @return list<string>
     */
    public static function interopNotifiables(): array
    {
        return array_map(static fn (string $class): string => ltrim($class, '\\'), Config::list('notifications.interop.notifiables', []));
    }

    // Routes -----------------------------------------------------------------------------

    public static function routesPrefix(): string
    {
        return trim(Config::string('notifications.routes.prefix', 'notifications'), '/');
    }

    /**
     * @return list<string>
     */
    public static function routesMiddleware(): array
    {
        return Config::list('notifications.routes.middleware', ['api']);
    }

    /**
     * @return list<string>
     */
    public static function routesAuthMiddleware(): array
    {
        $value = config('notifications.routes.auth_middleware');

        // An explicitly emptied list is honoured (the controllers still answer 401 without a user).
        if (is_array($value) && $value === []) {
            return [];
        }

        return Config::list('notifications.routes.auth_middleware', ['auth']);
    }

    public static function routesEnabled(): bool
    {
        return Config::boolean('notifications.routes.enabled', false);
    }

    // Resources --------------------------------------------------------------------------

    /**
     * @return class-string<JsonResource>
     */
    public static function actorResource(): string
    {
        return self::resource('notifications.resources.actor', config('notifications.resources.actor'), NotificationActorResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function subjectResource(): string
    {
        return self::resource('notifications.resources.subject', config('notifications.resources.subject'), NotificationSubjectResource::class);
    }

    /**
     * @return class-string<JsonResource>
     */
    public static function contextResource(): string
    {
        return self::resource('notifications.resources.context', config('notifications.resources.context'), NotificationContextResource::class);
    }

    // Prune ------------------------------------------------------------------------------

    public static function pruneInboxAfter(): CarbonInterval
    {
        return self::duration('notifications.prune.inbox_after', config('notifications.prune.inbox_after'), 'P180D', minSeconds: 3600);
    }

    public static function pruneDeliveriesAfter(): CarbonInterval
    {
        return self::duration('notifications.prune.deliveries_after', config('notifications.prune.deliveries_after'), 'P30D', minSeconds: 3600);
    }

    public static function pruneDigestRunsAfter(): CarbonInterval
    {
        return self::duration('notifications.prune.digest_runs_after', config('notifications.prune.digest_runs_after'), 'P90D', minSeconds: 3600);
    }

    public static function pruneRemindersAfter(): CarbonInterval
    {
        return self::duration('notifications.prune.reminders_after', config('notifications.prune.reminders_after'), 'P90D', minSeconds: 3600);
    }

    // Helpers ----------------------------------------------------------------------------

    public static function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private static function time(string $key, mixed $value, string $default): string
    {
        if (self::blank($value)) {
            return $default;
        }

        if (! is_string($value) || ! self::isTime($value)) {
            throw InvalidNotificationsConfigurationException::invalidTime($key, $value);
        }

        return $value;
    }

    private static function duration(string $key, mixed $value, string $default, int $minSeconds): CarbonInterval
    {
        if (self::blank($value)) {
            $value = $default;
        }

        $interval = is_string($value) ? Durations::iso($value) : null;

        if ($interval === null) {
            throw InvalidNotificationsConfigurationException::invalidDuration($key, $value);
        }

        if (Durations::seconds($interval) < $minSeconds) {
            throw InvalidNotificationsConfigurationException::durationOutOfRange($key, 'of at least '.$minSeconds.' seconds', $value);
        }

        return $interval;
    }

    private static function optionalString(string $key, mixed $value): ?string
    {
        if (self::blank($value)) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return trim($value);
    }

    /**
     * @param  class-string<JsonResource>  $default
     * @return class-string<JsonResource>
     */
    private static function resource(string $key, mixed $value, string $default): string
    {
        if (self::blank($value)) {
            return $default;
        }

        if (! is_string($value)
            || ! class_exists($value)
            || ! is_subclass_of($value, JsonResource::class)
            || ! (new ReflectionClass($value))->isInstantiable()) {
            throw InvalidConfigurationException::notAnImplementation($key, JsonResource::class, $value);
        }

        return $value;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
