<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Inbox;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Sending\RecordInboxItemAction;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationSubjectException;
use RoundlyConsulting\Notifications\Mail\InboxMessage;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\Access;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Contexts;
use RoundlyConsulting\Notifications\Support\KeyTypes;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\Payloads;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\PreparedSend;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Writes one InboxMessage (the Laravel `inbox` channel) into a recipient's inbox: the type's
 * access check and inbox preference apply, and it collapses like a send. Null when nothing was
 * written.
 */
final readonly class RecordInboxMessageAction
{
    public function __construct(
        private TypeRegistry $types,
        private RecordInboxItemAction $recordInboxItem,
    ) {}

    public function execute(Model $recipient, InboxMessage $message): ?InboxItem
    {
        $type = $this->types->find($message->type);
        $expected = $type->subject();
        $subject = $message->subject;

        if ($subject !== null && ($expected === null || ! $subject instanceof $expected)) {
            throw InvalidNotificationSubjectException::wrongClass($type->key(), $expected === null ? '-' : class_basename($expected), class_basename($subject));
        }

        KeyTypes::assert($recipient, 'recipient');
        KeyTypes::assert($subject, 'subject');
        KeyTypes::assert($message->actor, 'actor');

        // As for a send: access is checked on the subject as it is now (soft-deleted, or changed
        // since the caller loaded it); gone is no access.
        $given = $subject !== null;
        $subject = $given ? Subjects::fresh($type, $subject) : null;

        if (! Access::allows($type, $recipient, $subject, $given) || ! Preferences::enabled($type, Channel::Inbox, $recipient)) {
            return null;
        }

        $send = new PreparedSend(
            type: $type,
            subject: $subject,
            subjectGiven: $given,
            data: Payloads::assertJson($message->data),
            actor: $message->actor,
            context: Contexts::resolve($message->context ?? $type->context($subject)),
            idempotencyKey: null,
            escalationLevel: 0,
            reminderId: null,
            reminder: null,
            at: Clock::now(),
        );

        return NotificationModels::inboxItem()::query()->getConnection()
            ->transaction(fn (): InboxItem => $this->recordInboxItem->execute($send, $recipient)->item, 3);
    }
}
