<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Sending;

use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\DataTransferObjects\SendResult;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Jobs\DeliverToRecipientsJob;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Contexts;
use RoundlyConsulting\Notifications\Support\Escalations;
use RoundlyConsulting\Notifications\Support\KeyTypes;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Payloads;
use RoundlyConsulting\Notifications\Support\PreparedSend;
use RoundlyConsulting\Notifications\Support\Queues;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * The one send pipeline: validate the type, subject, data and keys, resolve the context and
 * recipients, then deliver to each recipient (access, preferences, inbox collapse, mail de-dup,
 * quiet hours, digest) — or queue that work in chunks above the fan-out threshold.
 */
final readonly class SendNotificationAction
{
    public function __construct(
        private TypeRegistry $types,
        private ResolveRecipientsAction $resolveRecipients,
        private DeliverToRecipientAction $deliver,
    ) {}

    public function execute(SendNotificationData $data): SendResult
    {
        $type = $this->types->find($data->type);
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

        $recipients = $this->resolveRecipients->execute($type, $data->recipients, $subject, $data->actor);

        $send = new PreparedSend(
            type: $type,
            subject: $subject,
            subjectGiven: $subject !== null,
            data: $payload,
            actor: $data->actor,
            context: $context,
            idempotencyKey: $data->idempotencyKey,
            escalationLevel: $data->escalationLevel,
            reminderId: $data->reminderId,
            reminder: $data->reminder,
            at: Clock::now(),
        );

        if ($data->escalations !== []) {
            Escalations::armForSend($type, $subject, $data, $send->at);
        }

        if (count($recipients) > NotificationsConfig::fanOutAbove() && NotificationsConfig::queueEnabled()) {
            foreach (array_chunk($recipients, NotificationsConfig::fanOutChunk()) as $chunk) {
                Queues::dispatch(new DeliverToRecipientsJob(
                    type: $type->key(),
                    subject: $subject === null ? null : ModelRefs::ref($subject),
                    recipients: array_map(ModelRefs::ref(...), $chunk),
                    data: $payload,
                    actor: $data->actor === null ? null : ModelRefs::ref($data->actor),
                    context: $context,
                    idempotencyKey: $data->idempotencyKey,
                    escalationLevel: $data->escalationLevel,
                    reminderId: $data->reminderId,
                ));
            }

            return new SendResult($type->key(), queued: count($recipients));
        }

        $outcomes = [];

        foreach ($recipients as $recipient) {
            $outcomes[] = $this->deliver->execute($send, $recipient);
        }

        return new SendResult($type->key(), $outcomes);
    }
}
