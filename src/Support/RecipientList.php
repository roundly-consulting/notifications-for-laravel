<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Exceptions\InvalidRecipientException;

/**
 * Normalises resolved recipients: nulls dropped, duplicates removed by morph type and key,
 * non-models refused, the actor removed unless the type notifies actors.
 *
 * @internal
 */
final class RecipientList
{
    /**
     * @param  iterable<mixed>  $resolved
     * @return list<Model>
     */
    public static function normalise(iterable $resolved, ?Model $actor, bool $notifyActor): array
    {
        $actorIdentity = $actor === null || $notifyActor ? null : ModelRefs::identity($actor);
        $seen = [];
        $recipients = [];

        foreach ($resolved as $recipient) {
            if ($recipient === null) {
                continue;
            }

            if (! $recipient instanceof Model) {
                throw InvalidRecipientException::notAModel(is_object($recipient) ? class_basename($recipient) : get_debug_type($recipient));
            }

            $identity = ModelRefs::identity($recipient);

            if ($identity === $actorIdentity || isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $recipients[] = $recipient;
        }

        return $recipients;
    }
}
