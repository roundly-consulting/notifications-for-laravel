<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Reminders;

use Carbon\CarbonInterval;
use Closure;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;

/**
 * One escalation level: if the subject is not acknowledged `after` the previous send, notify
 * `to` — with `type`, or the same type flagged as escalated.
 */
final readonly class EscalationRule
{
    public function __construct(
        public CarbonInterval $after,
        public RecipientSpec|Closure $to,
        public ?string $type = null,
    ) {}
}
