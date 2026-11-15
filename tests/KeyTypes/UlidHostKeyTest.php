<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Enums\ReminderKind;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Mail\TypedMailNotification;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Reminders\Reminder;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\UlidMember;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\UlidPublication;

/**
 * Repeating reminders with ULID keys everywhere — recipients, subject and the package's own ids:
 * re-timing on save, snooze, cancel(for:) and restart.
 */
beforeEach(function (): void {
    Notification::fake();
    at('2026-11-09 10:00:00');

    Notifications::define('publication.unacknowledged')
        ->subject(UlidPublication::class)
        ->access(fn (): bool => true)
        ->title(fn (): string => 'Please acknowledge the publication')
        ->reminders(Reminder::every('7 days')->from('published_at'))
        ->register();
});

function member(string $name): UlidMember
{
    return UlidMember::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test']);
}

function ulidOpenRow(UlidMember $member): ?NotificationReminder
{
    return NotificationReminder::query()
        ->where('kind', ReminderKind::Reminder->value)
        ->where('recipient_id', $member->id)
        ->where('status', ReminderStatus::Pending->value)
        ->first();
}

it('controls repeating chains of ulid-keyed recipients and subjects', function (): void {
    $publication = UlidPublication::query()->create(['title' => 'Policy', 'published_at' => CarbonImmutable::now('UTC')]);
    [$ana, $bob] = [member('Ana'), member('Bob')];

    Notifications::for($publication)->reminders()->schedule('publication.unacknowledged', [$ana, $bob]);
    $row = ulidOpenRow($ana);

    expect(Str::isUlid((string) $row?->id))->toBeTrue()
        ->and($row?->recipient_id)->toBe($ana->id)
        ->and($row?->subject_id)->toBe($publication->id);

    // N1: republished — the save re-times both chains.
    $publication->update(['published_at' => CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC')]);

    expect(ulidOpenRow($ana)?->due_at->toDateTimeString())->toBe('2026-11-17 10:00:00');

    at('2026-11-17 10:00:00');
    Notifications::reminders()->fireDue();
    Notification::assertSentToTimes($ana, TypedMailNotification::class, 1);

    // Snooze Ana, stop Bob.
    expect(Notifications::for($publication)->reminders()->snooze('publication.unacknowledged', $ana, until: CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC')))->toBe(1)
        ->and(Notifications::for($publication)->reminders()->cancel('publication.unacknowledged', for: $bob))->toBe(1)
        ->and(ulidOpenRow($ana)?->due_at->toDateTimeString())->toBe('2026-11-20 12:00:00')
        ->and(ulidOpenRow($bob))->toBeNull();

    at('2026-11-20 12:00:00');
    Notifications::reminders()->fireDue();

    expect(ulidOpenRow($ana)?->due_at->toDateTimeString())->toBe('2026-11-27 12:00:00');

    // Bob back on: a fresh chain at the anchor's next slot.
    expect(Notifications::for($publication)->reminders()->schedule('publication.unacknowledged', $bob, restart: true))->toHaveCount(1)
        ->and(ulidOpenRow($bob)?->due_at->toDateTimeString())->toBe('2026-11-24 10:00:00');
});

it('records the controls for ulid models under the fake', function (): void {
    $publication = UlidPublication::query()->create(['title' => 'Policy', 'published_at' => CarbonImmutable::now('UTC')]);
    $ana = member('Ana');

    $fake = Notifications::fake()->withReminders();
    Notifications::for($publication)->reminders()->schedule('publication.unacknowledged', $ana);
    Notifications::for($publication)->reminders()->snooze('publication.unacknowledged', $ana, until: CarbonImmutable::parse('2026-11-12 10:00:00', 'UTC'));
    Notifications::for($publication)->reminders()->cancel('publication.unacknowledged', for: $ana);
    Notifications::for($publication)->reminders()->schedule('publication.unacknowledged', $ana, restart: true);

    $fake->assertRemindersSnoozed($publication, $ana, 'publication.unacknowledged');
    $fake->assertRemindersCancelled($publication, for: $ana);
    $fake->assertReminderScheduled($publication, 'publication.unacknowledged', restart: true);

    expect(ulidOpenRow($ana)?->due_at->toDateTimeString())->toBe('2026-11-16 10:00:00');
});
