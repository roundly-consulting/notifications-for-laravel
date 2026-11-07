<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Types;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use RoundlyConsulting\Notifications\NotificationPayload;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications\VendorThingHappened;

/**
 * The type a third-party package's notification is mapped to.
 */
final class VendorThing extends NotificationType
{
    public function key(): string
    {
        return 'vendor.thing';
    }

    public function category(): string
    {
        return 'vendor';
    }

    public function subject(): ?string
    {
        return Post::class;
    }

    public function subjectFrom(Notification $notification): ?Model
    {
        return $notification instanceof VendorThingHappened ? $notification->post : null;
    }

    public function window(): ?string
    {
        return 'PT15M';
    }

    public function title(NotificationPayload $payload): string
    {
        return 'Vendor thing on post '.$payload->subjectKey;
    }
}
