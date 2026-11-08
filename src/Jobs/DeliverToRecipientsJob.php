<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Notifications\Actions\Sending\DeliverToRecipientAction;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextReference;
use RoundlyConsulting\Notifications\DataTransferObjects\ReminderReference;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\PreparedSend;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * One chunk of a large send. It carries references only, re-reads every model, and the per-
 * recipient step re-checks access — a recipient who lost access while the job waited gets
 * nothing.
 */
final class DeliverToRecipientsJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array{type: string, id: int|string}|null  $subject
     * @param  list<array{type: string, id: int|string}>  $recipients
     * @param  array<string, mixed>  $data
     * @param  array{type: string, id: int|string}|null  $actor
     */
    public function __construct(
        public readonly string $type,
        public readonly ?array $subject,
        public readonly array $recipients,
        public readonly array $data,
        public readonly ?array $actor,
        public readonly ?ContextReference $context,
        public readonly ?string $idempotencyKey,
        public readonly int $escalationLevel,
        public readonly int|string|null $reminderId,
        public readonly ?ReminderReference $reminder = null,
    ) {}

    public function handle(TypeRegistry $types, DeliverToRecipientAction $deliver): void
    {
        $type = $types->get($this->type);

        if ($type === null) {
            return;
        }

        $subject = $this->subject === null ? null : Subjects::find($type, $this->subject['type'], $this->subject['id']);

        $send = new PreparedSend(
            type: $type,
            subject: $subject,
            subjectGiven: $this->subject !== null,
            data: $this->data,
            actor: ModelRefs::load($this->actor),
            context: $this->context,
            idempotencyKey: $this->idempotencyKey,
            escalationLevel: $this->escalationLevel,
            reminderId: $this->reminderId,
            reminder: $this->reminder,
            at: Clock::now(),
        );

        foreach ($this->recipients as $ref) {
            $recipient = ModelRefs::load($ref);

            if ($recipient !== null) {
                $deliver->execute($send, $recipient);
            }
        }
    }
}
