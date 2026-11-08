<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferencesUpdate;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Facades\Notifications;
use RoundlyConsulting\Notifications\Mail\InboxMessage;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Testing\NotificationsFake;
use RoundlyConsulting\Notifications\Testing\RecordedOperation;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Types\RequestAwaitingApproval;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Types\SecurityAlert;

/*
 * The fake's assertions: each has a passing and a failing case, and calls made through the model
 * traits (ReceivesNotifications, HasNotificationReminders) are recorded like facade calls.
 */

it('records and writes nothing when a subject is deleted under the fake', function (): void {
    config()->set('notifications.inbox.on_subject_deleted', 'delete');
    $owner = user();
    $contract = contract(owner: $owner);
    Notifications::send('contract.expiring', $contract, $owner);

    $fake = Notifications::fake();
    $contract->delete();

    expect(InboxItem::query()->count())->toBe(1);
    $fake->assertSubjectForgotten($contract);
    $fake->assertRemindersCancelled($contract);
    expect(fn () => $fake->assertSubjectForgotten(contract()))->toThrow(ExpectationFailedException::class, 'Expected the inbox items of the subject to be forgotten');
});

it('records no forget for a deleted subject while items are kept', function (): void {
    $fake = Notifications::fake();
    $contract = contract();

    $contract->delete();

    $fake->assertNotRecorded(NotificationOperation::SubjectDeleted);
});

it('matches a class-string type in assertSent and assertNotSent', function (): void {
    $fake = Notifications::fake();
    $approver = user();
    $request = purchaseRequest();

    Notifications::send(RequestAwaitingApproval::class, $request, $approver);

    $fake->assertSent(RequestAwaitingApproval::class, to: $approver, about: $request);
    $fake->assertSent('request.awaiting_approval', to: $approver);
    $fake->assertNotSent(SecurityAlert::class);
    expect(fn () => $fake->assertNotSent(RequestAwaitingApproval::class))->toThrow(ExpectationFailedException::class, 'Expected [request.awaiting_approval] not to be sent');
});

it('narrows assertNotSent to a recipient and a subject', function (): void {
    $fake = Notifications::fake();
    $alice = user();
    $bob = user();
    $request = purchaseRequest();

    Notifications::send('request.awaiting_approval', $request, $alice);

    $fake->assertNotSent('request.awaiting_approval', to: $bob);
    $fake->assertNotSent('request.awaiting_approval', to: fn (Model $recipient): bool => $recipient->is($bob));
    $fake->assertNotSent('request.awaiting_approval', about: purchaseRequest());
    expect(fn () => $fake->assertNotSent('request.awaiting_approval', to: $alice))->toThrow(ExpectationFailedException::class, 'not to be sent to the recipient')
        ->and(fn () => $fake->assertNotSent('request.awaiting_approval', to: $alice, about: $request))->toThrow(ExpectationFailedException::class, 'not to be sent');
});

it('asserts any recorded operation, with a filter and a count', function (): void {
    $fake = Notifications::fake();
    Notifications::digests()->sendDue();
    Notifications::deliveries()->dispatchDue();
    Notifications::reminders()->fireDue();
    Notifications::reminders()->backfill('contract.expiring');
    Notifications::reminders()->backfill('contract.expiring');

    $fake->assertRecorded(NotificationOperation::SendDigests);
    $fake->assertRecorded(NotificationOperation::DispatchDeliveries, times: 1);
    $fake->assertRecorded(NotificationOperation::FireReminders);
    $fake->assertRecorded(NotificationOperation::BackfillReminders, fn (RecordedOperation $op): bool => $op->context['type'] === 'contract.expiring', times: 2);
    $fake->assertNotRecorded(NotificationOperation::Prune);
    $fake->assertNotRecorded(NotificationOperation::BackfillReminders, fn (RecordedOperation $op): bool => $op->context['type'] === 'other.type');

    expect(fn () => $fake->assertRecorded(NotificationOperation::Prune))->toThrow(ExpectationFailedException::class, 'Expected [prune] to be recorded, but it was not.')
        ->and(fn () => $fake->assertRecorded(NotificationOperation::BackfillReminders, times: 1))->toThrow(ExpectationFailedException::class, 'Expected [backfill_reminders] to be recorded 1 time(s), but it was recorded 2 time(s).')
        ->and(fn () => $fake->assertNotRecorded(NotificationOperation::SendDigests))->toThrow(ExpectationFailedException::class, 'Expected [send_digests] not to be recorded, but it was recorded 1 time(s).');
});

it('passes and fails each named assertion', function (Closure $act, Closure $pass, Closure $fail, string $message): void {
    $fake = Notifications::fake();
    $user = user();
    $other = user();

    $act($user);

    $pass($fake, $user, $other);
    expect(fn () => $fail($fake, $user, $other))->toThrow(ExpectationFailedException::class, $message);
})->with([
    'marked unread (trait)' => [
        fn (Model $user) => $user->inbox()->markUnread(1),
        fn (NotificationsFake $fake, Model $user) => $fake->assertMarkedUnread($user),
        fn (NotificationsFake $fake, Model $user, Model $other) => $fake->assertMarkedUnread($other),
        'Expected an inbox item to be marked unread',
    ],
    'unarchived' => [
        fn (Model $user) => Notifications::inbox($user)->unarchive(1),
        fn (NotificationsFake $fake, Model $user) => $fake->assertUnarchived($user),
        fn (NotificationsFake $fake, Model $user, Model $other) => $fake->assertUnarchived($other),
        'Expected an inbox item to be unarchived',
    ],
    'deleted' => [
        fn (Model $user) => Notifications::inbox($user)->delete(1),
        fn (NotificationsFake $fake) => $fake->assertDeleted(),
        fn (NotificationsFake $fake, Model $user, Model $other) => $fake->assertDeleted($other),
        'Expected an inbox item to be deleted',
    ],
    'category preference set (trait)' => [
        fn (Model $user) => $user->notificationPreferences()->muteCategory('comments'),
        fn (NotificationsFake $fake, Model $user) => $fake->assertCategoryPreferenceSet($user, 'comments', Channel::Mail, false),
        fn (NotificationsFake $fake, Model $user) => $fake->assertCategoryPreferenceSet($user, 'comments', Channel::Mail, null),
        'Expected category [comments] mail to be cleared, but it was not.',
    ],
    'category preference cleared' => [
        fn (Model $user) => Notifications::preferences($user)->unmuteCategory('comments', Channel::Mail),
        fn (NotificationsFake $fake, Model $user) => $fake->assertCategoryPreferenceSet($user, 'comments', Channel::Mail, null),
        fn (NotificationsFake $fake, Model $user) => $fake->assertCategoryPreferenceSet($user, 'comments', Channel::Inbox, true),
        'Expected category [comments] inbox to be set on, but it was not.',
    ],
    'preferences reset' => [
        fn (Model $user) => Notifications::preferences($user)->reset(),
        fn (NotificationsFake $fake, Model $user) => $fake->assertPreferencesReset($user),
        fn (NotificationsFake $fake, Model $user, Model $other) => $fake->assertPreferencesReset($other),
        'Expected the user\'s preferences to be reset',
    ],
    'digest set' => [
        fn (Model $user) => Notifications::preferences($user)->digest(DigestFrequency::Weekly, '08:00'),
        function (NotificationsFake $fake, Model $user): void {
            $fake->assertDigestSet($user, DigestFrequency::Weekly);
            $fake->assertDigestSet($user, DigestFrequency::Weekly, '08:00');
        },
        fn (NotificationsFake $fake, Model $user) => $fake->assertDigestSet($user, DigestFrequency::Weekly, '09:00'),
        'Expected a weekly digest at 09:00 to be set, but it was not.',
    ],
    'digest frequency' => [
        fn (Model $user) => Notifications::preferences($user)->digest(DigestFrequency::Daily),
        fn (NotificationsFake $fake, Model $user) => $fake->assertDigestSet($user, DigestFrequency::Daily),
        fn (NotificationsFake $fake, Model $user) => $fake->assertDigestSet($user, DigestFrequency::Weekly),
        'Expected a weekly digest to be set, but it was not.',
    ],
    'quiet hours set' => [
        fn (Model $user) => Notifications::preferences($user)->quietHours('22:00', '07:00'),
        fn (NotificationsFake $fake, Model $user) => $fake->assertQuietHoursSet($user, '22:00', '07:00'),
        fn (NotificationsFake $fake, Model $user) => $fake->assertQuietHoursSet($user, null, null),
        'Expected quiet hours to be cleared, but they were not.',
    ],
    'quiet hours cleared' => [
        fn (Model $user) => Notifications::preferences($user)->quietHours(null),
        fn (NotificationsFake $fake, Model $user) => $fake->assertQuietHoursSet($user, null, null),
        fn (NotificationsFake $fake, Model $user) => $fake->assertQuietHoursSet($user, '22:00', '07:00'),
        'Expected quiet hours 22:00–07:00 to be set, but they were not.',
    ],
    'timezone set' => [
        fn (Model $user) => Notifications::preferences($user)->timezone('Europe/Bratislava'),
        fn (NotificationsFake $fake, Model $user) => $fake->assertTimezoneSet($user, 'Europe/Bratislava'),
        fn (NotificationsFake $fake, Model $user) => $fake->assertTimezoneSet($user, null),
        'Expected the timezone to be cleared, but it was not.',
    ],
    'timezone cleared' => [
        fn (Model $user) => Notifications::preferences($user)->timezone(null),
        fn (NotificationsFake $fake, Model $user) => $fake->assertTimezoneSet($user, null),
        fn (NotificationsFake $fake, Model $user) => $fake->assertTimezoneSet($user, 'UTC'),
        'Expected the timezone [UTC] to be set, but it was not.',
    ],
    'inbox message (channel)' => [
        fn (Model $user) => $user->notify(new class extends Notification
        {
            /** @return list<string> */
            public function via(object $notifiable): array
            {
                return ['inbox'];
            }

            public function toInbox(object $notifiable): InboxMessage
            {
                return new InboxMessage('security.alert');
            }
        }),
        function (NotificationsFake $fake, Model $user): void {
            $fake->assertInboxMessage($user);
            $fake->assertInboxMessage($user, 'security.alert');
        },
        fn (NotificationsFake $fake, Model $user) => $fake->assertInboxMessage($user, 'comment.posted'),
        'Expected an inbox message [comment.posted] to be recorded, but none was.',
    ],
    'pruned' => [
        fn () => Notifications::prune(),
        fn (NotificationsFake $fake) => $fake->assertPruned(),
        fn (NotificationsFake $fake) => $fake->assertNotRecorded(NotificationOperation::Prune),
        'Expected [prune] not to be recorded',
    ],
    'nothing marked read' => [
        fn (Model $user) => $user->inbox()->markAllRead(),
        fn (NotificationsFake $fake) => $fake->assertMarkedRead(),
        fn (NotificationsFake $fake) => $fake->assertNothingMarkedRead(),
        'Expected nothing to be marked read, but 1 operation(s) were recorded.',
    ],
    'nothing archived' => [
        fn (Model $user) => Notifications::inbox($user)->archive(1),
        fn (NotificationsFake $fake) => $fake->assertArchived(),
        fn (NotificationsFake $fake) => $fake->assertNothingArchived(),
        'Expected nothing to be archived, but 1 operation(s) were recorded.',
    ],
    'no preference changed (trait)' => [
        fn (Model $user) => $user->notificationPreferences()->update(new PreferencesUpdate(channels: ['comment.posted' => ['mail' => false]])),
        fn (NotificationsFake $fake, Model $user) => $fake->assertPreferencesUpdated($user),
        fn (NotificationsFake $fake) => $fake->assertNoPreferenceChanged(),
        'Expected no preference to change, but 1 write(s) were recorded.',
    ],
    'no reminders scheduled (trait)' => [
        fn () => contract(),
        fn (NotificationsFake $fake) => $fake->assertRecorded(NotificationOperation::SyncReminders),
        fn (NotificationsFake $fake) => $fake->assertNoRemindersScheduled(),
        'Expected no reminders to be scheduled, but 1 operation(s) were recorded.',
    ],
]);

it('passes the nothing-assertions on an untouched fake', function (): void {
    $fake = Notifications::fake();

    $fake->assertNothingMarkedRead();
    $fake->assertNothingArchived();
    $fake->assertNoPreferenceChanged();
    $fake->assertNoRemindersScheduled();
    $fake->assertNotRecorded(NotificationOperation::Send);
});
