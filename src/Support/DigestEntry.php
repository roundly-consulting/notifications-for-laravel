<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * One line of a digest: the parked delivery, its type and its (fresh) subject.
 *
 * @internal
 */
final readonly class DigestEntry
{
    public function __construct(
        public NotificationDelivery $delivery,
        public NotificationType $type,
        public ?Model $subject,
    ) {}
}
