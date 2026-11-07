<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;

/**
 * A pending mail delivery, due now.
 *
 * @extends Factory<NotificationDelivery>
 */
final class NotificationDeliveryFactory extends Factory
{
    /** @var class-string<NotificationDelivery> */
    protected $model = NotificationDelivery::class;

    public function modelName(): string
    {
        return NotificationModels::delivery();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipient_type' => 'user',
            'recipient_id' => 1,
            'type' => 'general.notice',
            'channel' => 'mail',
            'status' => DeliveryStatus::Pending,
            'send_after' => Clock::now(),
            'events_count' => 1,
            'first_event_at' => Clock::now(),
            'last_event_at' => Clock::now(),
            'data' => [],
            'source' => NotificationDelivery::SOURCE_TYPE,
            'attempts' => 0,
        ];
    }

    public function recipient(Model $recipient): self
    {
        return $this->state(fn (): array => ['recipient_type' => $recipient->getMorphClass(), 'recipient_id' => $recipient->getKey()]);
    }

    public function status(DeliveryStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
