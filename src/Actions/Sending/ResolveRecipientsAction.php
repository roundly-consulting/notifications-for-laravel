<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Sending;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Support\RecipientList;

/**
 * Resolves a recipient spec with the subject and normalises the result (nulls and duplicates
 * dropped, the actor removed unless the type notifies actors).
 *
 * @internal
 */
final readonly class ResolveRecipientsAction
{
    /**
     * @return list<Model>
     */
    public function execute(NotificationType $type, RecipientSpec $recipients, ?Model $subject, ?Model $actor): array
    {
        return RecipientList::normalise($recipients->resolve($subject), $actor, $type->notifyActor());
    }
}
