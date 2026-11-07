<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\RoutesNotifications;
use Illuminate\Support\Facades\Gate;
use ReflectionMethod;
use RoundlyConsulting\Notifications\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Notifications\DefinedType;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\NotificationType;
use Throwable;

/**
 * The checks behind `notifications:doctor`.
 *
 * @internal
 */
final class Doctor
{
    /**
     * @return list<DoctorFinding>
     */
    public static function run(): array
    {
        $findings = [];

        $options = config('options.key_type');
        $optionsKeyType = is_string($options) && trim($options) !== '' ? strtolower(trim($options)) : 'bigint';

        if ($optionsKeyType !== NotificationsConfig::keyType()->value) {
            $findings[] = new DoctorFinding('error', sprintf('notifications.key_type (%s) differs from options.key_type (%s): preferences are stored per user through options.', NotificationsConfig::keyType()->value, $optionsKeyType));
        }

        if (NotificationsConfig::queueEnabled() && (NotificationsConfig::queueConnection() ?? config('queue.default')) === 'sync') {
            $findings[] = new DoctorFinding('info', 'The queue connection is sync: mail, digests and fan-out run inline.');
        }

        $store = NotificationsConfig::lockStore() ?? config('cache.default');
        $driver = is_string($store) ? config('cache.stores.'.$store.'.driver') : null;

        if (in_array($driver, ['array', 'file'], true)) {
            $findings[] = new DoctorFinding('warning', sprintf('The preference lock store [%s] uses the %s driver: it is not shared across servers (set notifications.preferences.lock_store).', $store, $driver));
        }

        try {
            NotificationsConfig::defaultQuietHours();
        } catch (Throwable $exception) {
            $findings[] = new DoctorFinding('error', $exception->getMessage());
        }

        array_push($findings, ...self::types(), ...self::interop(), ...self::userModel());

        $findings[] = new DoctorFinding('info', "Schedule: Schedule::command('notifications:sweep')->everyMinute()->withoutOverlapping(10)->onOneServer();");

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private static function types(): array
    {
        $findings = [];
        $ability = NotificationsConfig::accessAbility();

        foreach (app(TypeRegistry::class)->all() as $type) {
            $subject = $type->subject();

            if ($subject === null || self::overrides($type, 'canAccess')) {
                continue;
            }

            if (Gate::getPolicyFor($subject) === null && ! Gate::has($ability)) {
                $findings[] = new DoctorFinding('error', sprintf('Type [%s]: %s has no policy and the [%s] ability is not defined — the first send throws.', $type->key(), class_basename($subject), $ability));
            }
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private static function interop(): array
    {
        $findings = [];
        $types = app(TypeRegistry::class);

        foreach (app(InteropRules::class)->mapped() as $notification => $key) {
            $type = $types->get($key);
            $name = class_basename($notification);

            if ($type === null) {
                $findings[] = new DoctorFinding('error', sprintf('Interop: %s maps to the unknown type [%s].', $name, $key));

                continue;
            }

            if (InteropRules::timeCritical($notification) && ! InteropRules::fitsTimeCritical($type)) {
                $findings[] = new DoctorFinding('error', sprintf('Interop: %s is time-critical and may only map to an urgent type without a window ([%s] is not).', $name, $key));
            }

            if ($type->digestible() && $type->priority() === Priority::Low) {
                $findings[] = new DoctorFinding('warning', sprintf('Interop: [%s] is digestible, but mapped notifications are never digested — %s is delivered immediately.', $key, $name));
            }

            if ($type->subject() !== null && ! self::overrides($type, 'subjectFrom')) {
                $findings[] = new DoctorFinding('warning', sprintf('Interop: [%s] has a subject but no subjectFrom(): %s gets preferences and quiet hours only — no access check, no de-dup.', $key, $name));
            }
        }

        return $findings;
    }

    /**
     * @return list<DoctorFinding>
     */
    private static function userModel(): array
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            return [];
        }

        $findings = [];
        $name = class_basename($model);

        if (NotificationsConfig::addressResolver() === null && ! in_array(RoutesNotifications::class, class_uses_recursive($model), true)) {
            $findings[] = new DoctorFinding('error', sprintf('%s cannot be mailed: use Notifiable (or RoutesNotifications), or set notifications.mail.address_resolver.', $name));
        }

        if (! is_subclass_of($model, Authenticatable::class)) {
            $findings[] = new DoctorFinding('warning', sprintf('%s is not Authenticatable: the default Gate access check expects one.', $name));
        }

        return $findings;
    }

    private static function overrides(NotificationType $type, string $method): bool
    {
        if ($type instanceof DefinedType) {
            return $type->defines($method === 'canAccess' ? 'access' : $method);
        }

        return (new ReflectionMethod($type, $method))->getDeclaringClass()->getName() !== NotificationType::class;
    }
}
