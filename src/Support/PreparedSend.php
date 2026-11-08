<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextReference;
use RoundlyConsulting\Notifications\DataTransferObjects\ReminderReference;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * One validated send, ready to deliver to each recipient.
 *
 * @internal
 */
final readonly class PreparedSend
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public NotificationType $type,
        public ?Model $subject,
        public bool $subjectGiven,
        public array $data,
        public ?Model $actor,
        public ?ContextReference $context,
        public ?string $idempotencyKey,
        public int $escalationLevel,
        public int|string|null $reminderId,
        public ?ReminderReference $reminder,
        public CarbonImmutable $at,
        public ?string $title = null,
        public bool $collapses = true,
    ) {}

    public function lane(): string
    {
        return Keys::lane($this->escalationLevel);
    }

    public function subjectIdentity(): ?string
    {
        return $this->subject === null ? null : ModelRefs::identity($this->subject);
    }

    public function subjectKey(): int|string|null
    {
        $key = $this->subject?->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }

    public function payload(Model $recipient, Channel $channel, int $count = 1, ?CarbonImmutable $firstAt = null): NotificationPayload
    {
        return new NotificationPayload(
            type: $this->type->key(),
            recipient: $recipient,
            subject: $this->subject,
            subjectKey: $this->subjectKey(),
            actor: $this->actor,
            context: $this->context,
            data: $this->data,
            count: $count,
            firstAt: $firstAt ?? $this->at,
            lastAt: $this->at,
            channel: $channel,
            escalated: $this->escalationLevel > 0,
            reminder: $this->reminder,
        );
    }
}
