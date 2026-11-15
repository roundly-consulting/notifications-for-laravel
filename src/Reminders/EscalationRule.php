<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Reminders;

use Carbon\CarbonInterval;
use Closure;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;

/**
 * One escalation level: if the subject is not acknowledged `after` the previous send, notify
 * `to` — with `type`, or the same type flagged as escalated. On a repeating rule `firings` (when
 * set) arms the level once that many reminders went out, due `after` (one interval) later.
 */
final readonly class EscalationRule
{
    public function __construct(
        public CarbonInterval $after,
        public RecipientSpec|Closure $to,
        public ?string $type = null,
        public ?int $firings = null,
    ) {}
}
