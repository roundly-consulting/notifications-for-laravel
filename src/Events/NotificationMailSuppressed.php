<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;

/**
 * A mail was not sent because the SuppressionList suppresses an address it would go to: a typed
 * mail (`$delivery`, now undeliverable) or a digest (`$digestRun`, now failed).
 */
final readonly class NotificationMailSuppressed implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $addresses  the suppressed addresses
     */
    public function __construct(
        public Model $recipient,
        public array $addresses,
        public ?NotificationDelivery $delivery = null,
        public ?DigestRun $digestRun = null,
    ) {}
}
