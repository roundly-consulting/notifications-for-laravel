<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Notifications\Models\NotificationReceipt;
use RoundlyConsulting\Notifications\Support\NotificationModels;

/**
 * An idempotency receipt.
 *
 * @extends Factory<NotificationReceipt>
 */
final class NotificationReceiptFactory extends Factory
{
    /** @var class-string<NotificationReceipt> */
    protected $model = NotificationReceipt::class;

    public function modelName(): string
    {
        return NotificationModels::receipt();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => str_repeat('a', 64),
            'recipient_type' => 'user',
            'recipient_id' => 1,
        ];
    }
}
