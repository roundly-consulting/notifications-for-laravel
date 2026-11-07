<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use RoundlyConsulting\Notifications\Actions\Delivery\DispatchDueDeliveriesAction;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * Mail deliveries: `Notifications::deliveries()->dispatchDue()` releases held, trailing and retried
 * mail that is due.
 */
final readonly class DeliveriesAccessor
{
    public function __construct(
        private NotificationsManager $manager,
    ) {}

    public function dispatchDue(): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::DispatchDeliveries,
            DispatchDueDeliveriesAction::class,
            static fn (DispatchDueDeliveriesAction $action): int => $action->execute(),
        );
    }
}
