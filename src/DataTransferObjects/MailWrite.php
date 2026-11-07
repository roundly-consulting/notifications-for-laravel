<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use RoundlyConsulting\Notifications\Enums\MailOutcome;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;

/**
 * What the mail step did, and the delivery it wrote or folded into.
 */
final readonly class MailWrite
{
    public function __construct(
        public MailOutcome $outcome,
        public NotificationDelivery $delivery,
    ) {}
}
