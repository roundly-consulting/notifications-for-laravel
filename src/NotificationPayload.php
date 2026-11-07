<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextReference;
use RoundlyConsulting\Notifications\DataTransferObjects\ReminderReference;
use RoundlyConsulting\Notifications\Enums\Channel;

/**
 * Everything a type's rendering hooks see. `subject` is null whenever the subject is gone, so read
 * `subjectKey` or `data` for anything that must survive deletion. `count` is the number of events
 * collapsed into the inbox item (or folded into the mail).
 */
final readonly class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $type,
        public Model $recipient,
        public ?Model $subject,
        public int|string|null $subjectKey,
        public ?Model $actor,
        public ?ContextReference $context,
        public array $data,
        public int $count,
        public CarbonImmutable $firstAt,
        public CarbonImmutable $lastAt,
        public Channel $channel,
        public bool $escalated = false,
        public ?ReminderReference $reminder = null,
    ) {}
}
