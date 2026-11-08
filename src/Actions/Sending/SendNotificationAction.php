<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Sending;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\DataTransferObjects\RecipientOutcome;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\DataTransferObjects\SendResult;
use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Jobs\DeliverToRecipientsJob;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Escalations;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\PreparedSend;
use RoundlyConsulting\Notifications\Support\Queues;
use RoundlyConsulting\Notifications\Support\Receipts;
use RoundlyConsulting\Notifications\Support\SendValidation;
use RoundlyConsulting\Notifications\Support\Subjects;
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
        $validated = SendValidation::validate($data, $this->types);
        [$type, $payload, $context] = [$validated->type, $validated->data, $validated->context];
        // The send is a hop like any other: access is checked on the subject as it is now, not on
        // the caller's model (soft-deleted, or changed since it was loaded). Gone → no access.
        $subject = $data->subject === null ? null : Subjects::fresh($type, $data->subject);

        $recipients = $this->resolveRecipients->execute($type, $data->recipients, $subject ?? $data->subject, $data->actor);

        $send = new PreparedSend(
            type: $type,
            subject: $subject,
            subjectGiven: $data->subject !== null,
            data: $payload,
            actor: $data->actor,
            context: $context,
            idempotencyKey: $data->idempotencyKey,
            escalationLevel: $data->escalationLevel,
            reminderId: $data->reminderId,
            reminder: $data->reminder,
            at: Clock::now(),
        );

        if (count($recipients) > NotificationsConfig::fanOutAbove() && NotificationsConfig::queueEnabled()) {
            // A keyed replay that already reached everyone arms nothing.
            $replay = $data->idempotencyKey !== null && Receipts::reachedAll($data->idempotencyKey, $recipients);

            // Without a key of its own, the fan-out gets one: a job retried by the queue after it
            // delivered part of its chunk does not deliver to those recipients again.
            $key = $data->idempotencyKey ?? 'fanout:'.Str::ulid();

            foreach (array_chunk($recipients, NotificationsConfig::fanOutChunk()) as $chunk) {
                Queues::dispatch(new DeliverToRecipientsJob(
                    type: $type->key(),
                    subject: $data->subject === null ? null : ModelRefs::ref($data->subject),
                    recipients: array_map(ModelRefs::ref(...), $chunk),
                    data: $payload,
                    actor: $data->actor === null ? null : ModelRefs::ref($data->actor),
                    context: $context,
                    idempotencyKey: $key,
                    escalationLevel: $data->escalationLevel,
                    reminderId: $data->reminderId,
                    reminder: $data->reminder,
                ));
            }

            if (! $replay) {
                $this->arm($type, $subject, $data, $send);
            }

            return new SendResult($type->key(), queued: count($recipients));
        }

        $outcomes = [];

        foreach ($recipients as $recipient) {
            $outcomes[] = $this->deliver->execute($send, $recipient);
        }

        // Armed only once delivery went through (a throw above arms nothing), and not for a keyed
        // replay every recipient had already received — after its chain fired or was acknowledged.
        $replay = $outcomes !== [] && array_all($outcomes, static fn (RecipientOutcome $outcome): bool => $outcome->inbox === InboxOutcome::Duplicate);

        if (! $replay) {
            $this->arm($type, $subject, $data, $send);
        }

        return new SendResult($type->key(), $outcomes);
    }

    private function arm(NotificationType $type, ?Model $subject, SendNotificationData $data, PreparedSend $send): void
    {
        if ($data->escalations !== [] && $subject !== null) {
            Escalations::armForSend($type, $subject, $data, $send->at);
        }
    }
}
