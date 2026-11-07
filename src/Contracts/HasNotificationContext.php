<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

/**
 * A model used as an inbox context (a company, a team) that names its own display label.
 */
interface HasNotificationContext
{
    public function notificationContextLabel(): string;
}
