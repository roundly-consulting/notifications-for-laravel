<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;

/**
 * A pending daily digest run for today.
 *
 * @extends Factory<DigestRun>
 */
final class DigestRunFactory extends Factory
{
    /** @var class-string<DigestRun> */
    protected $model = DigestRun::class;

    public function modelName(): string
    {
        return NotificationModels::digestRun();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'recipient_type' => 'user',
            'recipient_id' => 1,
            'frequency' => DigestFrequency::Daily,
            'period' => Clock::now()->format('Y-m-d'),
            'status' => DigestRunStatus::Pending,
            'items_count' => 0,
            'attempts' => 0,
        ];
    }

    public function recipient(Model $recipient): self
    {
        return $this->state(fn (): array => ['recipient_type' => $recipient->getMorphClass(), 'recipient_id' => $recipient->getKey()]);
    }
}
