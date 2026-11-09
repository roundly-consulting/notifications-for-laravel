<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Notifications\DataTransferObjects\MailAttempt;

/**
 * The rate limit of typed mail, asked right before a mail goes to the mailer (with
 * `notifications.mail.rate_limit.enabled` on). Bind through `notifications.mail.rate_limit.throttle`.
 */
interface ThrottlesMail
{
    /**
     * Null: send the mail now (and count it). A time: the mail is over a limit until then, and
     * nothing is counted — it is deferred to that time or skipped
     * (`notifications.mail.rate_limit.when_limited`).
     */
    public function attempt(MailAttempt $attempt): ?CarbonImmutable;
}
