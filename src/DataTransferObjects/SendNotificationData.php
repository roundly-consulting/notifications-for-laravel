<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Context;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Reminders\EscalationRule;

/**
 * One send: the type key, the subject it is about, who to notify, plain-JSON data, the actor,
 * an optional context and idempotency key, and send-level escalation levels.
 *
 * `escalationLevel`, `reminderId` and `reminder` are set by the reminder and escalation fire
 * steps; leave them at their defaults.
 */
final readonly class SendNotificationData
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<EscalationRule>  $escalations
     */
    public function __construct(
        public string $type,
        public ?Model $subject,
        public RecipientSpec $recipients,
        public array $data = [],
        public ?Model $actor = null,
        public Context|Model|string|null $context = null,
        public ?string $idempotencyKey = null,
        public array $escalations = [],
        public int $escalationLevel = 0,
        public int|string|null $reminderId = null,
        public ?ReminderReference $reminder = null,
    ) {}
}
