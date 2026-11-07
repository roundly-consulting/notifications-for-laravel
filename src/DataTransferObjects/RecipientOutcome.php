<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Enums\MailOutcome;

/**
 * What one send did for one recipient: the inbox and the mail outcome, and the rows written.
 * `recipientType` is the model's basename, never its class.
 */
final readonly class RecipientOutcome
{
    public function __construct(
        public int|string $recipientKey,
        public string $recipientType,
        public InboxOutcome $inbox,
        public MailOutcome $mail,
        public int|string|null $inboxItemId = null,
        public int|string|null $deliveryId = null,
    ) {}
}
