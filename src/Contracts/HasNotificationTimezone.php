<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

/**
 * A recipient that names its own IANA timezone — the first source the default resolver asks.
 */
interface HasNotificationTimezone
{
    public function notificationTimezone(): ?string;
}
