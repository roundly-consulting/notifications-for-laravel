<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use RoundlyConsulting\Notifications\Contracts\ThrottlesMail;
use RoundlyConsulting\Notifications\DataTransferObjects\MailAttempt;
use RoundlyConsulting\Notifications\DataTransferObjects\MailRateLimit;
use RoundlyConsulting\Notifications\Enums\Priority;

/**
 * Counts typed mail with Laravel's RateLimiter (the `cache.limiter` store): per recipient and per
 * context (`notifications.mail.rate_limit.per_*`). Urgent types are never limited.
 *
 * Each cap is counted increment-first — the cache increments atomically — so two workers can
 * never both take the last slot. A mail over a cap gives back what it counted.
 */
final readonly class DefaultMailThrottle implements ThrottlesMail
{
    public function __construct(
        private RateLimiter $limiter,
    ) {}

    public function attempt(MailAttempt $attempt): ?CarbonImmutable
    {
        if ($attempt->type->priority() === Priority::Urgent) {
            return null;
        }

        $caps = [];

        if (($perRecipient = NotificationsConfig::rateLimitPerRecipient()) !== null) {
            $caps['notifications:mail:recipient:'.Keys::digest(ModelRefs::identity($attempt->recipient))] = $perRecipient;
        }

        if ($attempt->contextKey !== null && ($perContext = NotificationsConfig::rateLimitPerContext()) !== null) {
            $caps['notifications:mail:context:'.Keys::digest($attempt->contextKey)] = $perContext;
        }

        $counted = [];

        foreach ($caps as $key => $cap) {
            $hits = $this->limiter->increment($key, $cap->seconds);
            $counted[$key] = $cap;

            if ($hits > $cap->max) {
                $this->giveBack($counted);

                return Clock::now()->addSeconds(max(1, $this->limiter->availableIn($key)));
            }
        }

        return null;
    }

    /**
     * @param  array<string, MailRateLimit>  $counted
     */
    private function giveBack(array $counted): void
    {
        foreach ($counted as $key => $cap) {
            $this->limiter->decrement($key, $cap->seconds);
        }
    }
}
