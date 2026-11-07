<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A group (a team, a company) that lists its members, optionally those with one role — what the
 * default group resolver behind Recipients::role() / Recipients::members() reads.
 */
interface ProvidesNotificationRecipients
{
    /**
     * @return iterable<Model>
     */
    public function notificationRecipients(?string $role = null): iterable;
}
