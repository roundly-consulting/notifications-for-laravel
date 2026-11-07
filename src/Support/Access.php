<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * The access check every hop runs: may this recipient still see the subject?
 *
 * @internal
 */
final class Access
{
    /**
     * @param  bool  $subjectGiven  whether the send had a subject (a gone subject is no access)
     */
    public static function allows(NotificationType $type, Model $recipient, ?Model $subject, bool $subjectGiven): bool
    {
        if ($subject === null) {
            return ! $subjectGiven;
        }

        return $type->canAccess($recipient, $subject);
    }
}
