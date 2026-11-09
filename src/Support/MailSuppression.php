<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Contracts\SuppressionList;

/**
 * The SuppressionList check before a typed mail or digest: which of the addresses the mail would
 * go to are suppressed. One suppressed address stops the whole mail — a mail to a bounced or
 * complaining address costs the sender's reputation more than a missed co-recipient.
 *
 * @internal
 */
final class MailSuppression
{
    /**
     * The suppressed addresses (empty: the mail may go).
     *
     * @param  string|bool  $address  what MailAddresses::resolve() answered
     * @return list<string>
     */
    public static function suppressed(Model $recipient, string|bool $address): array
    {
        $list = app(SuppressionList::class);

        return array_values(array_filter(
            self::addresses($recipient, $address),
            static fn (string $candidate): bool => $list->isSuppressed($candidate),
        ));
    }

    /**
     * The addresses a mail goes to, read the way Laravel's mail channel reads a route: a string,
     * a list of addresses (or objects with an `email`), or a map of address => name.
     *
     * @return list<string>
     */
    public static function addresses(Model $recipient, string|bool $address): array
    {
        if (is_string($address)) {
            return [trim($address)];
        }

        if ($address === false || ! method_exists($recipient, 'routeNotificationFor')) {
            return [];
        }

        $route = $recipient->routeNotificationFor('mail');

        if (is_string($route)) {
            return [trim($route)];
        }

        if (! is_iterable($route)) {
            return [];
        }

        $addresses = [];

        foreach ($route as $key => $value) {
            $candidate = match (true) {
                is_string($key) => $key,
                is_string($value) => $value,
                is_object($value) && isset($value->email) && is_string($value->email) => $value->email,
                default => null,
            };

            if ($candidate !== null && trim($candidate) !== '') {
                $addresses[] = trim($candidate);
            }
        }

        return $addresses;
    }
}
