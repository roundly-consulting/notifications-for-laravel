<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonInterval;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationTypeException;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * A type's collapse / de-dup window, read once: null for none, at most one day.
 *
 * @internal
 */
final class Windows
{
    public const int MAX_SECONDS = 86_400;

    public static function of(NotificationType $type): ?CarbonInterval
    {
        $window = $type->window();

        if ($window === null) {
            return null;
        }

        $interval = Durations::lenient($window);

        if ($interval === null || Durations::seconds($interval) > self::MAX_SECONDS) {
            throw InvalidNotificationTypeException::invalidWindow($type->key());
        }

        return $interval;
    }

    public static function seconds(NotificationType $type): ?int
    {
        $interval = self::of($type);

        return $interval === null ? null : Durations::seconds($interval);
    }

    /**
     * Urgent types always mail on the leading edge.
     */
    public static function strategy(NotificationType $type): MailStrategy
    {
        return $type->priority() === Priority::Urgent ? MailStrategy::Leading : $type->mailStrategy();
    }
}
