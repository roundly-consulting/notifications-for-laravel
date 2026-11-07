<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Types;

use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Recipients;
use RoundlyConsulting\Notifications\Reminders\Reminder;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;

/**
 * The reminder type: 60, 30 and 7 days before `expires_at`, while not terminated, escalating to the
 * company's managers when nobody acknowledged within three days.
 */
final class ContractExpiring extends NotificationType
{
    public function key(): string
    {
        return 'contract.expiring';
    }

    public function category(): string
    {
        return 'contracts';
    }

    public function subject(): ?string
    {
        return Contract::class;
    }

    public function reminders(): array
    {
        return [
            Reminder::before('expires_at', 'P60D', 'P30D', 'P7D')
                ->to(Recipients::owner())
                ->while(fn (Contract $contract): bool => $contract->terminated_at === null)
                ->escalate(after: 'P3D', to: fn (Contract $contract) => Recipients::role($contract->company, 'manager')),
        ];
    }

    public function title(NotificationPayload $payload): string
    {
        return 'Contract #'.$payload->subjectKey.' expires soon ('.(string) ($payload->data['reminder'] ?? '-').')';
    }

    public function url(NotificationPayload $payload): ?string
    {
        return 'https://app.test/contracts/'.$payload->subjectKey;
    }
}
