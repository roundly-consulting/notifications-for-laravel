<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * One typed mail about to be sent — what a ThrottlesMail decides on.
 */
final readonly class MailAttempt
{
    public function __construct(
        public Model $recipient,
        public NotificationType $type,
        public ?string $contextKey,
        public int|string $deliveryId,
    ) {}
}
