<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RoundlyConsulting\Notifications\Contracts\ResolvesMailAddress;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Where a recipient's mail goes — one answer for typed mail and digests, so
 * `notifications.mail.address_resolver` holds for both.
 *
 * @internal
 */
final class MailAddresses
{
    /**
     * True: route through the recipient. A string: an explicit address. False: undeliverable.
     *
     * @param  NotificationPayload|null  $payload  null for a digest
     */
    public static function resolve(Model $recipient, ?NotificationPayload $payload): string|bool
    {
        $resolver = NotificationsConfig::addressResolver();

        if ($resolver !== null) {
            if (! class_exists($resolver) || ! is_subclass_of($resolver, ResolvesMailAddress::class) || ! (new ReflectionClass($resolver))->isInstantiable()) {
                throw InvalidConfigurationException::notAnImplementation('notifications.mail.address_resolver', ResolvesMailAddress::class, $resolver);
            }

            $instance = app()->make($resolver);

            if (! $instance instanceof ResolvesMailAddress) {
                throw InvalidConfigurationException::notAnImplementation('notifications.mail.address_resolver', ResolvesMailAddress::class, $resolver);
            }

            $address = $instance->address($recipient, $payload);

            return $address === null || trim($address) === '' ? false : $address;
        }

        if (! method_exists($recipient, 'routeNotificationFor')) {
            return false;
        }

        $route = $recipient->routeNotificationFor('mail');

        return $route === null || $route === '' || $route === [] ? false : true;
    }
}
