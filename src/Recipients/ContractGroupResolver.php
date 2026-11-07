<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Recipients;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Contracts\ProvidesNotificationRecipients;
use RoundlyConsulting\Notifications\Contracts\ResolvesGroupMembers;
use RoundlyConsulting\Notifications\Exceptions\UnresolvableRecipientsException;

/**
 * The default group resolver: the group lists its own members through
 * ProvidesNotificationRecipients.
 */
final class ContractGroupResolver implements ResolvesGroupMembers
{
    public function members(Model $group, ?string $role = null): iterable
    {
        if (! $group instanceof ProvidesNotificationRecipients) {
            throw UnresolvableRecipientsException::forGroup(class_basename($group));
        }

        return $group->notificationRecipients($role);
    }
}
