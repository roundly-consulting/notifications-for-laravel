<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Builders;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Context;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\DataTransferObjects\SendResult;
use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Recipients\UserRecipients;
use RoundlyConsulting\Notifications\Reminders\EscalationRule;
use RoundlyConsulting\Notifications\Support\Durations;

/**
 * The fluent form of a send — the same pipeline:
 *
 * ```php
 * Notifications::type(RequestAwaitingApproval::class)->about($request)->to($approvers)->send();
 * ```
 */
final class PendingNotification
{
    private ?Model $subject = null;

    private RecipientSpec $recipients;

    private ?Model $actor = null;

    /** @var array<string, mixed> */
    private array $data = [];

    private Context|Model|string|null $context = null;

    private ?string $idempotencyKey = null;

    /** @var list<EscalationRule> */
    private array $escalations = [];

    public function __construct(
        private readonly NotificationsManager $manager,
        private readonly string $type,
    ) {
        $this->recipients = new UserRecipients([]);
    }

    public function about(?Model $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * @param  RecipientSpec|Model|Closure|iterable<mixed>  $recipients
     */
    public function to(RecipientSpec|Model|Closure|iterable $recipients): self
    {
        $this->recipients = Recipients::from($recipients);

        return $this;
    }

    public function by(?Model $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function with(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Overrides the type's context().
     */
    public function in(Context|Model|string|null $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * An idempotency key: a replay of the same key sends nothing to a recipient it reached.
     */
    public function once(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * If the subject is not acknowledged `after` the send, notify `to`. Repeat for more levels.
     * An unreadable or non-positive delay throws here, never escalates at once.
     *
     * @param  RecipientSpec|Model|iterable<mixed>  $to
     *
     * @throws InvalidReminderRuleException
     */
    public function escalate(CarbonInterval|string $after, RecipientSpec|Model|iterable $to, ?string $type = null): self
    {
        $this->escalations[] = new EscalationRule(Durations::lenient($after) ?? throw InvalidReminderRuleException::invalidDelay($after), Recipients::from($to), $type);

        return $this;
    }

    public function data(): SendNotificationData
    {
        return new SendNotificationData(
            type: $this->type,
            subject: $this->subject,
            recipients: $this->recipients,
            data: $this->data,
            actor: $this->actor,
            context: $this->context,
            idempotencyKey: $this->idempotencyKey,
            escalations: $this->escalations,
        );
    }

    public function send(): SendResult
    {
        return $this->manager->sendNotification($this->data());
    }
}
