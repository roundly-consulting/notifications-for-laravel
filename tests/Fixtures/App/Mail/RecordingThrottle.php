<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Mail;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Notifications\Contracts\ThrottlesMail;
use RoundlyConsulting\Notifications\DataTransferObjects\MailAttempt;

/**
 * A host throttle: records every attempt and answers `$until`.
 */
final class RecordingThrottle implements ThrottlesMail
{
    /** @var list<MailAttempt> */
    public static array $attempts = [];

    public static ?CarbonImmutable $until = null;

    public function attempt(MailAttempt $attempt): ?CarbonImmutable
    {
        self::$attempts[] = $attempt;

        return self::$until;
    }
}
