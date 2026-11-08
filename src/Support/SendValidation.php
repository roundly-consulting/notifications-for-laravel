<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;

/**
 * The checks a send runs before it touches the store: the type exists, the subject is of the
 * type's class (and present when escalating), the data is plain JSON, the keys fit, the context
 * is valid and escalation recipients can be stored. They are programmer errors, so the fake runs
 * them too and throws the same exceptions.
 *
 * @internal
 */
final class SendValidation
{
    public static function validate(SendNotificationData $data, TypeRegistry $types): ValidatedSend
    {
        $type = $types->find($data->type);
        $subject = $data->subject;
        $expected = $type->subject();

        if ($subject !== null) {
            if ($expected === null) {
                throw InvalidNotificationSubjectException::unexpected($type->key(), class_basename($subject));
            }

            if (! $subject instanceof $expected) {
                throw InvalidNotificationSubjectException::wrongClass($type->key(), class_basename($expected), class_basename($subject));
            }
        }

        if ($data->escalations !== [] && $subject === null) {
            throw InvalidNotificationSubjectException::required($type->key());
        }

        $payload = Payloads::assertJson($data->data);

        KeyTypes::assert($subject, 'subject');
        KeyTypes::assert($data->actor, 'actor');

        $context = Contexts::resolve($data->context ?? $type->context($subject));

        // Stored escalation recipients must survive a JSON round trip: refuse closures up front.
        foreach ($data->escalations as $escalation) {
            Escalations::storedRecipients($escalation->to);
        }

        return new ValidatedSend($type, $payload, $context);
    }
}
