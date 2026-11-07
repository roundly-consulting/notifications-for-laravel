<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use RoundlyConsulting\Notifications\Support\InteropRules;

/**
 * Other packages' notifications at runtime, beside `notifications.interop`:
 * `Notifications::interop()->map(TicketAssignedNotification::class, 'tickets.assigned')`.
 */
final readonly class InteropAccessor
{
    public function __construct(
        private InteropRules $rules,
    ) {}

    /**
     * Route a notification class (and its subclasses) through a type.
     */
    public function map(string $notification, string $type): self
    {
        $this->rules->map($notification, $type);

        return $this;
    }

    /**
     * Never send a notification class — on every channel, or the listed ones.
     *
     * @param  list<string>|null  $channels
     */
    public function suppress(string $notification, ?array $channels = null): self
    {
        $this->rules->suppress($notification, $channels);

        return $this;
    }

    /**
     * @return array<string, string> notification class => type key
     */
    public function mapped(): array
    {
        return $this->rules->mapped();
    }

    /**
     * @return array<string, list<string>|null> notification class => channels (null = all)
     */
    public function suppressed(): array
    {
        return $this->rules->suppressed();
    }
}
