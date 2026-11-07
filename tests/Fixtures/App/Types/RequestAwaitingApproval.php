<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Types;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PurchaseRequest;

/**
 * The README example: a window of five minutes, the company as context, Gate "view" access.
 */
final class RequestAwaitingApproval extends NotificationType
{
    public function key(): string
    {
        return 'request.awaiting_approval';
    }

    public function category(): string
    {
        return 'requests';
    }

    public function subject(): ?string
    {
        return PurchaseRequest::class;
    }

    public function window(): ?string
    {
        return 'PT5M';
    }

    public function title(NotificationPayload $payload): string
    {
        return __('app.awaiting', ['ref' => $payload->data['reference'] ?? '?', 'count' => $payload->count]);
    }

    public function body(NotificationPayload $payload): ?string
    {
        $note = $payload->data['note'] ?? null;

        return is_string($note) ? $note : null;
    }

    public function url(NotificationPayload $payload): ?string
    {
        return 'https://app.test/requests/'.$payload->subjectKey;
    }

    public function context(?Model $subject): ?Model
    {
        return $subject instanceof PurchaseRequest ? $subject->company : null;
    }
}
