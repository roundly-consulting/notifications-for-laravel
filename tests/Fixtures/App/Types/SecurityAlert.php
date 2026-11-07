<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Types;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * The mandatory type: cannot be muted, urgent (bypasses quiet hours), sensitive (minimal mail).
 */
final class SecurityAlert extends NotificationType
{
    public function key(): string
    {
        return 'security.alert';
    }

    public function category(): string
    {
        return 'security';
    }

    public function mandatory(): bool
    {
        return true;
    }

    public function priority(): Priority
    {
        return Priority::Urgent;
    }

    public function sensitive(?Model $subject): bool
    {
        return true;
    }

    public function title(NotificationPayload $payload): string
    {
        return __('app.security_alert', ['what' => $payload->data['what'] ?? 'activity']);
    }

    public function body(NotificationPayload $payload): ?string
    {
        return 'Secret detail: '.(string) ($payload->data['ip'] ?? '-');
    }

    public function url(NotificationPayload $payload): ?string
    {
        return 'https://app.test/account/security';
    }
}
