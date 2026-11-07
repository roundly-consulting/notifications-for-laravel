<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;

/**
 * An unread inbox item; pass a recipient with `recipient($user)`.
 *
 * @extends Factory<InboxItem>
 */
final class InboxItemFactory extends Factory
{
    /** @var class-string<InboxItem> */
    protected $model = InboxItem::class;

    public function modelName(): string
    {
        return NotificationModels::inboxItem();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipient_type' => 'user',
            'recipient_id' => 1,
            'type' => 'general.notice',
            'category' => 'general',
            'priority' => Priority::Normal,
            'count' => 1,
            'data' => [],
            'title' => $this->faker->sentence(4),
            'first_event_at' => Clock::now(),
            'last_event_at' => Clock::now(),
        ];
    }

    public function recipient(Model $recipient): self
    {
        return $this->state(fn (): array => ['recipient_type' => $recipient->getMorphClass(), 'recipient_id' => $recipient->getKey()]);
    }

    public function read(): self
    {
        return $this->state(fn (): array => ['read_at' => Clock::now()]);
    }

    public function archived(): self
    {
        return $this->state(fn (): array => ['archived_at' => Clock::now()]);
    }
}
