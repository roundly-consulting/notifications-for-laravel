<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use RoundlyConsulting\Notifications\Enums\InboxOutcome;
use RoundlyConsulting\Notifications\Enums\MailOutcome;

/**
 * What a send did, per recipient. Above the fan-out threshold the work is queued: `queued` counts
 * the recipients handed to jobs, and their outcomes are not known yet.
 */
final readonly class SendResult
{
    /**
     * @param  list<RecipientOutcome>  $outcomes
     */
    public function __construct(
        public string $type,
        public array $outcomes = [],
        public int $queued = 0,
    ) {}

    /**
     * @return list<RecipientOutcome>
     */
    public function inbox(InboxOutcome $outcome): array
    {
        return array_values(array_filter($this->outcomes, static fn (RecipientOutcome $o): bool => $o->inbox === $outcome));
    }

    /**
     * @return list<RecipientOutcome>
     */
    public function mail(MailOutcome $outcome): array
    {
        return array_values(array_filter($this->outcomes, static fn (RecipientOutcome $o): bool => $o->mail === $outcome));
    }

    /**
     * The keys of every recipient the send reached (not skipped for access or a replay).
     *
     * @return list<int|string>
     */
    public function recipients(): array
    {
        return array_values(array_map(
            static fn (RecipientOutcome $o): int|string => $o->recipientKey,
            array_filter($this->outcomes, static fn (RecipientOutcome $o): bool => ! in_array($o->inbox, [InboxOutcome::NoAccess, InboxOutcome::Duplicate], true)),
        ));
    }

    /**
     * The recipients skipped for lost access or a replayed idempotency key.
     *
     * @return list<RecipientOutcome>
     */
    public function skipped(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (RecipientOutcome $o): bool => in_array($o->inbox, [InboxOutcome::NoAccess, InboxOutcome::Duplicate], true),
        ));
    }
}
