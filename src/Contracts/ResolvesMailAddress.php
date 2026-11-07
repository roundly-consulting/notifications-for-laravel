<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\NotificationPayload;

/**
 * Where a recipient's mail goes — typed mail and digests alike. Null makes the delivery
 * undeliverable. Bind through `notifications.mail.address_resolver` (the contacts "verified only"
 * recipe uses it).
 */
interface ResolvesMailAddress
{
    /**
     * @param  NotificationPayload|null  $payload  the typed mail's payload; null for a digest
     */
    public function address(Model $recipient, ?NotificationPayload $payload): ?string;
}
