<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;

/**
 * A pending absolute reminder due in a day; pass a subject with `subject($model)`.
 *
 * @extends Factory<NotificationReminder>
 */
final class NotificationReminderFactory extends Factory
{
    /** @var class-string<NotificationReminder> */
    protected $model = NotificationReminder::class;

    public function modelName(): string
    {
        return NotificationModels::reminder();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => ReminderKind::Reminder,
            'type' => 'general.notice',
            'subject_type' => 'subject',
            'subject_id' => 1,
            'due_at' => Clock::now()->addDay(),
            'level' => 0,
            'status' => ReminderStatus::Pending,
            'attempts' => 0,
        ];
    }

    public function subject(Model $subject): self
    {
        return $this->state(fn (): array => ['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()]);
    }
}
