<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Maintenance;

use RoundlyConsulting\Notifications\Actions\Delivery\DispatchDueDeliveriesAction;
use RoundlyConsulting\Notifications\Actions\Delivery\ExpireStaleClaimsAction;
use RoundlyConsulting\Notifications\Actions\Digests\SendDueDigestsAction;
use RoundlyConsulting\Notifications\Actions\Reminders\FireDueRemindersAction;
use RoundlyConsulting\Notifications\DataTransferObjects\SweepReport;
use RoundlyConsulting\Notifications\Enums\ReminderKind;

/**
 * Everything time-driven, in one scheduled command (`notifications:sweep`, every minute): expire
 * stale claims, release due mail, start due digests, fire due reminders and escalations.
 */
final readonly class SweepAction
{
    public const array PARTS = ['claims', 'deliveries', 'digests', 'reminders', 'escalations'];

    public function __construct(
        private ExpireStaleClaimsAction $expire,
        private DispatchDueDeliveriesAction $deliveries,
        private SendDueDigestsAction $digests,
        private FireDueRemindersAction $reminders,
    ) {}

    /**
     * @param  list<string>|null  $only  a subset of PARTS
     */
    public function execute(?array $only = null): SweepReport
    {
        $runs = static fn (string $part): bool => $only === null || in_array($part, $only, true);

        $expired = $runs('claims') ? $this->expire->execute() : 0;
        $deliveries = $runs('deliveries') ? $this->deliveries->execute() : 0;
        $digests = $runs('digests') ? $this->digests->execute() : 0;
        $fired = match (true) {
            $runs('reminders') && $runs('escalations') => $this->reminders->execute(),
            $runs('reminders') => $this->reminders->execute(ReminderKind::Reminder),
            $runs('escalations') => $this->reminders->execute(ReminderKind::Escalation),
            default => null,
        };

        return new SweepReport(
            deliveries: $deliveries,
            digests: $digests,
            reminders: $fired === null ? 0 : $fired->reminders,
            escalations: $fired === null ? 0 : $fired->escalations,
            expired: $expired,
        );
    }
}
