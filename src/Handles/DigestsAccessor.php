<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use RoundlyConsulting\Notifications\Actions\Digests\PreviewDigestAction;
use RoundlyConsulting\Notifications\Actions\Digests\SendDueDigestsAction;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * Digests: `Notifications::digests()->sendDue()`, `->preview($user)`.
 */
final readonly class DigestsAccessor
{
    public function __construct(
        private NotificationsManager $manager,
    ) {}

    /**
     * Start every due digest run; returns how many.
     */
    public function sendDue(): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::SendDigests,
            SendDueDigestsAction::class,
            static fn (SendDueDigestsAction $action): int => $action->execute(),
        );
    }

    /**
     * The digest mail a user would get now from their parked items (null when none) — nothing is
     * sent or marked.
     */
    public function preview(Model $user): ?MailMessage
    {
        return app(PreviewDigestAction::class)->execute($user);
    }
}
