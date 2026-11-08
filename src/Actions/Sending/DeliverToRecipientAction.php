<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Sending;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Notifications\DataTransferObjects\RecipientOutcome;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Enums\MailOutcome;
use RoundlyConsulting\Notifications\Events\RecipientSkipped;
use RoundlyConsulting\Notifications\Jobs\DeliverMailJob;
use RoundlyConsulting\Notifications\Support\Access;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\DeliveryStates;
use RoundlyConsulting\Notifications\Support\KeyTypes;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\PreparedSend;
use RoundlyConsulting\Notifications\Support\Queues;
use RoundlyConsulting\Notifications\Support\Receipts;

/**
 * One recipient of one send, in one transaction (deadlocks retried when the package owns the
 * outermost transaction): idempotency receipt, access check, effective channels, the inbox step
 * and the mail step. A due mail is dispatched after the commit.
 *
 * @internal
 */
final readonly class DeliverToRecipientAction
{
    public function __construct(
        private RecordInboxItemAction $recordInboxItem,
        private QueueMailDeliveryAction $queueMail,
    ) {}

    public function execute(PreparedSend $send, Model $recipient): RecipientOutcome
    {
        KeyTypes::assert($recipient, 'recipient');

        $connection = (new (NotificationModels::inboxItem()))->getConnection();

        return $connection->transaction(fn (): RecipientOutcome => $this->deliver($send, $recipient), 3);
    }

    private function deliver(PreparedSend $send, Model $recipient): RecipientOutcome
    {
        $key = ModelRefs::key($recipient);
        $basename = class_basename($recipient);

        if ($send->idempotencyKey !== null && ! Receipts::claim($send->idempotencyKey, $recipient)) {
            return new RecipientOutcome($key, $basename, InboxOutcome::Duplicate, MailOutcome::Duplicate);
        }

        if (! Access::allows($send->type, $recipient, $send->subject, $send->subjectGiven)) {
            Event::dispatch(new RecipientSkipped($send->type->key(), $recipient, 'no_access'));

            return new RecipientOutcome($key, $basename, InboxOutcome::NoAccess, MailOutcome::NoAccess);
        }

        $channels = Preferences::channels($send->type, $recipient);
        $typeChannels = $send->type->channels();

        $inboxOutcome = in_array(Channel::Inbox, $typeChannels, true) ? InboxOutcome::Muted : InboxOutcome::NotInChannels;
        $item = null;

        if (in_array(Channel::Inbox, $channels, true)) {
            $write = $this->recordInboxItem->execute($send, $recipient);
            $inboxOutcome = $write->outcome;
            $item = $write->item;
        }

        $mailOutcome = in_array(Channel::Mail, $typeChannels, true) ? MailOutcome::Muted : MailOutcome::NotInChannels;
        $delivery = null;

        if (in_array(Channel::Mail, $channels, true)) {
            $write = $this->queueMail->execute($send, $recipient, $item);
            $mailOutcome = $write->outcome;
            $delivery = $write->delivery;

            if ($delivery->status === DeliveryStatus::Pending && $delivery->send_after !== null
                && $delivery->send_after->lessThanOrEqualTo(Clock::now())
                && in_array($mailOutcome, [MailOutcome::Pending], true)) {
                $id = ModelRefs::key($delivery);
                DeliveryStates::dispatched([$id]);
                Queues::dispatch(new DeliverMailJob($id));
            }
        }

        return new RecipientOutcome(
            $key,
            $basename,
            $inboxOutcome,
            $mailOutcome,
            $item === null ? null : ModelRefs::key($item),
            $delivery === null ? null : ModelRefs::key($delivery),
        );
    }
}
