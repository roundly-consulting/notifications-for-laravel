<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Notifications\Actions\Sending\ResolveRecipientsAction;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferencesUpdate;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Support\SendValidation;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use Throwable;

/**
 * Installed by `Notifications::fake()`. Record-only: every mutating call — sends (also through
 * the builder and `for()`), inbox mutations, preference writes, reminder operations, sweeps and
 * calls through the model traits — is recorded and NOT executed. Reads pass through to the real
 * store. `passthrough()` records and runs.
 *
 * Recipient resolution still runs, so `assertSent(to: $user)` works with `Recipients::role()`.
 * Access checks, preferences, quiet hours and de-dup do not: an assertion proves the intent to
 * notify, not a delivery (use the real pipeline with `Notification::fake()` for that).
 */
final class NotificationsFake extends NotificationsManager
{
    /** @var list<RecordedOperation> */
    private array $recorded = [];

    private bool $passthrough = false;

    /**
     * Record and also run every operation.
     */
    public function passthrough(bool $passthrough = true): self
    {
        $this->passthrough = $passthrough;

        return $this;
    }

    /**
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult|null
     */
    public function perform(NotificationOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        // The interop listener runs inside other packages' sends: never record or block it.
        if ($operation === NotificationOperation::Interop) {
            return $this->passthrough ? parent::perform($operation, $action, $execute, $context) : null;
        }

        $recorded = $this->record($operation, $context);     // may throw what the real send throws
        $this->recorded[] = $recorded;

        return $this->passthrough ? parent::perform($operation, $action, $execute, $context) : null;
    }

    /**
     * @return list<RecordedOperation>
     */
    public function recorded(?NotificationOperation $operation = null): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (RecordedOperation $recorded): bool => $operation === null || $recorded->operation === $operation,
        ));
    }

    // Sends ------------------------------------------------------------------------------

    /**
     * The type by key or by its class.
     *
     * @param  Model|(Closure(Model): bool)|null  $to
     */
    public function assertSent(string $type, Model|Closure|null $to = null, ?Model $about = null, ?int $times = null): void
    {
        $type = $this->typeKey($type);
        $matching = $this->sends($type, $to, $about);

        if ($times !== null) {
            PHPUnit::assertCount($times, $matching, sprintf('Expected [%s] to be sent %d time(s), but it was sent %d time(s).', $type, $times, count($matching)));

            return;
        }

        PHPUnit::assertNotEmpty($matching, sprintf('Expected [%s] to be sent%s, but no send matched.', $type, $to === null ? '' : ' to the recipient'));
    }

    /**
     * The type by key or by its class; `to` / `about` narrow it to one recipient or subject.
     *
     * @param  Model|(Closure(Model): bool)|null  $to
     */
    public function assertNotSent(string $type, Model|Closure|null $to = null, ?Model $about = null): void
    {
        $type = $this->typeKey($type);
        $sent = $this->sends($type, $to, $about);

        PHPUnit::assertEmpty($sent, sprintf('Expected [%s] not to be sent%s, but it was sent %d time(s).', $type, $to === null ? '' : ' to the recipient', count($sent)));
    }

    public function assertNothingSent(): void
    {
        $sent = $this->recorded(NotificationOperation::Send);

        PHPUnit::assertEmpty($sent, sprintf('Expected nothing to be sent, but %d send(s) were recorded.', count($sent)));
    }

    // Inbox ------------------------------------------------------------------------------

    public function assertMarkedRead(?Model $recipient = null): void
    {
        $this->assertRecordedFor([NotificationOperation::MarkRead, NotificationOperation::MarkAllRead], 'recipient', $recipient, 'Expected an inbox item to be marked read, but none was.');
    }

    public function assertArchived(?Model $recipient = null): void
    {
        $this->assertRecordedFor([NotificationOperation::Archive], 'recipient', $recipient, 'Expected an inbox item to be archived, but none was.');
    }

    public function assertMarkedUnread(?Model $recipient = null): void
    {
        $this->assertRecordedFor([NotificationOperation::MarkUnread], 'recipient', $recipient, 'Expected an inbox item to be marked unread, but none was.');
    }

    public function assertUnarchived(?Model $recipient = null): void
    {
        $this->assertRecordedFor([NotificationOperation::Unarchive], 'recipient', $recipient, 'Expected an inbox item to be unarchived, but none was.');
    }

    public function assertDeleted(?Model $recipient = null): void
    {
        $this->assertRecordedFor([NotificationOperation::Delete], 'recipient', $recipient, 'Expected an inbox item to be deleted, but none was.');
    }

    /**
     * A message the Laravel `inbox` channel wrote (optionally of one type, by key or class).
     */
    public function assertInboxMessage(Model $recipient, ?string $type = null): void
    {
        $type = $type === null ? null : $this->typeKey($type);
        $matching = array_filter($this->recorded(NotificationOperation::InboxMessage), fn (RecordedOperation $op): bool => $op->concerns($recipient, 'recipient')
            && ($type === null || (is_string($op->context['type'] ?? null) && $this->typeKey($op->context['type']) === $type)));

        PHPUnit::assertNotEmpty($matching, sprintf('Expected an inbox message%s to be recorded, but none was.', $type === null ? '' : " [{$type}]"));
    }

    /**
     * The inbox cleanup of `notifications.inbox.on_subject_deleted` for a deleted subject.
     */
    public function assertSubjectForgotten(Model $subject): void
    {
        $this->assertRecordedFor([NotificationOperation::SubjectDeleted], 'subject', $subject, 'Expected the inbox items of the subject to be forgotten, but they were not.');
    }

    public function assertNothingMarkedRead(): void
    {
        $this->assertNothingOf([NotificationOperation::MarkRead, NotificationOperation::MarkAllRead], 'Expected nothing to be marked read, but %d operation(s) were recorded.');
    }

    public function assertNothingArchived(): void
    {
        $this->assertNothingOf([NotificationOperation::Archive], 'Expected nothing to be archived, but %d operation(s) were recorded.');
    }

    // Preferences ------------------------------------------------------------------------

    public function assertPreferenceSet(Model $user, string $type, Channel $channel, bool $enabled): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::SetPreference), static fn (RecordedOperation $set): bool => $set->concerns($user, 'user')
            && $set->context['type'] === $type
            && is_array($set->context['channels'])
            && in_array($channel, $set->context['channels'], true)
            && $set->context['enabled'] === $enabled);

        PHPUnit::assertNotEmpty($matching, sprintf('Expected [%s] %s to be set %s, but it was not.', $type, $channel->value, $enabled ? 'on' : 'off'));
    }

    public function assertMuted(Model $user, string $type): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::SetPreference), static fn (RecordedOperation $set): bool => $set->concerns($user, 'user')
            && $set->context['type'] === $type
            && $set->context['enabled'] === false);

        PHPUnit::assertNotEmpty($matching, sprintf('Expected [%s] to be muted, but it was not.', $type));
    }

    /**
     * `$enabled` null: the category's override was cleared.
     */
    public function assertCategoryPreferenceSet(Model $user, string $category, Channel $channel, ?bool $enabled): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::SetCategoryPreference), static fn (RecordedOperation $set): bool => $set->concerns($user, 'user')
            && $set->context['category'] === $category
            && is_array($set->context['channels'])
            && in_array($channel, $set->context['channels'], true)
            && $set->context['enabled'] === $enabled);

        PHPUnit::assertNotEmpty($matching, sprintf('Expected category [%s] %s to be %s, but it was not.', $category, $channel->value, match ($enabled) {
            null => 'cleared',
            true => 'set on',
            false => 'set off',
        }));
    }

    public function assertPreferencesReset(Model $user): void
    {
        $this->assertRecordedFor([NotificationOperation::ResetPreferences], 'user', $user, 'Expected the user\'s preferences to be reset, but they were not.');
    }

    /**
     * `$time` null matches any time.
     */
    public function assertDigestSet(Model $user, DigestFrequency $frequency, ?string $time = null): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::SetDigest), static fn (RecordedOperation $set): bool => $set->concerns($user, 'user')
            && $set->context['frequency'] === $frequency
            && ($time === null || $set->context['time'] === $time));

        PHPUnit::assertNotEmpty($matching, sprintf('Expected a %s digest%s to be set, but it was not.', $frequency->value, $time === null ? '' : " at {$time}"));
    }

    /**
     * Both null: quiet hours were cleared.
     */
    public function assertQuietHoursSet(Model $user, ?string $start, ?string $end): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::SetQuietHours), static fn (RecordedOperation $set): bool => $set->concerns($user, 'user')
            && $set->context['start'] === $start
            && ($start === null || $set->context['end'] === $end));

        PHPUnit::assertNotEmpty($matching, $start === null
            ? 'Expected quiet hours to be cleared, but they were not.'
            : sprintf('Expected quiet hours %s–%s to be set, but they were not.', $start, (string) $end));
    }

    /**
     * Null: the timezone was cleared.
     */
    public function assertTimezoneSet(Model $user, ?string $timezone): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::SetTimezone), static fn (RecordedOperation $set): bool => $set->concerns($user, 'user')
            && $set->context['timezone'] === $timezone);

        PHPUnit::assertNotEmpty($matching, $timezone === null
            ? 'Expected the timezone to be cleared, but it was not.'
            : sprintf('Expected the timezone [%s] to be set, but it was not.', $timezone));
    }

    /**
     * No preference write of any kind: type, category, reset, update, digest, quiet hours, timezone.
     */
    public function assertNoPreferenceChanged(): void
    {
        $this->assertNothingOf([
            NotificationOperation::SetPreference,
            NotificationOperation::SetCategoryPreference,
            NotificationOperation::ResetPreferences,
            NotificationOperation::UpdatePreferences,
            NotificationOperation::SetDigest,
            NotificationOperation::SetQuietHours,
            NotificationOperation::SetTimezone,
        ], 'Expected no preference to change, but %d write(s) were recorded.');
    }

    /**
     * @param  (Closure(PreferencesUpdate): bool)|null  $where
     */
    public function assertPreferencesUpdated(Model $user, ?Closure $where = null): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::UpdatePreferences), static fn (RecordedOperation $op): bool => $op->concerns($user, 'user')
            && $op->context['update'] instanceof PreferencesUpdate
            && ($where === null || $where($op->context['update'])));

        PHPUnit::assertNotEmpty($matching, 'Expected the user\'s preferences to be updated, but they were not.');
    }

    // Reminders --------------------------------------------------------------------------

    public function assertRemindersSynced(Model $subject): void
    {
        $this->assertRecordedFor([NotificationOperation::SyncReminders], 'subject', $subject, 'Expected the reminders of the subject to be synced, but they were not.');
    }

    public function assertReminderScheduled(Model $subject, string $type): void
    {
        $matching = array_filter(
            $this->recorded(),
            static fn (RecordedOperation $op): bool => in_array($op->operation, [NotificationOperation::ScheduleReminders, NotificationOperation::Remind], true)
                && $op->concerns($subject, 'subject')
                && ($op->context['type'] ?? null) === $type,
        );

        PHPUnit::assertNotEmpty($matching, sprintf('Expected a [%s] reminder to be scheduled, but none was.', $type));
    }

    /**
     * No reminder was scheduled, synced, set ad hoc or backfilled.
     */
    public function assertNoRemindersScheduled(): void
    {
        $this->assertNothingOf([
            NotificationOperation::ScheduleReminders,
            NotificationOperation::SyncReminders,
            NotificationOperation::Remind,
            NotificationOperation::BackfillReminders,
        ], 'Expected no reminders to be scheduled, but %d operation(s) were recorded.');
    }

    public function assertRemindersCancelled(Model $subject): void
    {
        $this->assertRecordedFor([NotificationOperation::CancelReminders], 'subject', $subject, 'Expected the reminders of the subject to be cancelled, but they were not.');
    }

    public function assertAcknowledged(Model $subject, ?string $type = null): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::Acknowledge), static fn (RecordedOperation $op): bool => $op->concerns($subject, 'subject')
            && ($type === null || ($op->context['type'] ?? null) === $type));

        PHPUnit::assertNotEmpty($matching, 'Expected the subject to be acknowledged, but it was not.');
    }

    public function assertRevoked(Model $subject, ?Model $recipient = null): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::Revoke), static fn (RecordedOperation $op): bool => $op->concerns($subject, 'subject')
            && ($recipient === null || $op->concerns($recipient, 'recipient')));

        PHPUnit::assertNotEmpty($matching, 'Expected notifications of the subject to be revoked, but they were not.');
    }

    public function assertSwept(): void
    {
        PHPUnit::assertNotEmpty($this->recorded(NotificationOperation::Sweep), 'Expected a sweep, but none ran.');
    }

    public function assertPruned(): void
    {
        PHPUnit::assertNotEmpty($this->recorded(NotificationOperation::Prune), 'Expected a prune, but none ran.');
    }

    // Any operation ----------------------------------------------------------------------

    /**
     * Any recorded operation — the ones without a named assertion too (digests, deliveries,
     * reminder fires, backfills) — optionally filtered and counted.
     *
     * @param  (Closure(RecordedOperation): bool)|null  $where
     */
    public function assertRecorded(NotificationOperation $operation, ?Closure $where = null, ?int $times = null): void
    {
        $matching = $this->matching($operation, $where);

        if ($times !== null) {
            PHPUnit::assertCount($times, $matching, sprintf('Expected [%s] to be recorded %d time(s), but it was recorded %d time(s).', $operation->value, $times, count($matching)));

            return;
        }

        PHPUnit::assertNotEmpty($matching, sprintf('Expected [%s] to be recorded, but it was not.', $operation->value));
    }

    /**
     * @param  (Closure(RecordedOperation): bool)|null  $where
     */
    public function assertNotRecorded(NotificationOperation $operation, ?Closure $where = null): void
    {
        $matching = $this->matching($operation, $where);

        PHPUnit::assertEmpty($matching, sprintf('Expected [%s] not to be recorded, but it was recorded %d time(s).', $operation->value, count($matching)));
    }

    // Internals --------------------------------------------------------------------------

    /**
     * @param  (Closure(RecordedOperation): bool)|null  $where
     * @return list<RecordedOperation>
     */
    private function matching(NotificationOperation $operation, ?Closure $where): array
    {
        return array_values(array_filter($this->recorded($operation), static fn (RecordedOperation $op): bool => $where === null || $where($op)));
    }

    /**
     * @param  Model|(Closure(Model): bool)|null  $to
     * @return list<RecordedOperation>
     */
    private function sends(string $type, Model|Closure|null $to, ?Model $about): array
    {
        return array_values(array_filter($this->recorded(NotificationOperation::Send), static function (RecordedOperation $send) use ($type, $to, $about): bool {
            if ($send->type !== $type) {
                return false;
            }

            if ($about !== null && ($send->subject === null || ! $send->subject->is($about))) {
                return false;
            }

            if ($to === null) {
                return true;
            }

            foreach ($send->recipients as $recipient) {
                if ($to instanceof Model ? $recipient->is($to) : $to($recipient)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * A type class-string becomes its key; an unknown key stays as given.
     */
    private function typeKey(string $type): string
    {
        return $this->container()->make(TypeRegistry::class)->get($type)?->key() ?? $type;
    }

    /**
     * @param  list<NotificationOperation>  $operations
     */
    private function assertNothingOf(array $operations, string $message): void
    {
        $matching = array_filter($this->recorded(), static fn (RecordedOperation $op): bool => in_array($op->operation, $operations, true));

        PHPUnit::assertEmpty($matching, sprintf($message, count($matching)));
    }

    /**
     * @param  list<NotificationOperation>  $operations
     */
    private function assertRecordedFor(array $operations, string $key, ?Model $model, string $message): void
    {
        $matching = array_filter(
            $this->recorded(),
            static fn (RecordedOperation $op): bool => in_array($op->operation, $operations, true) && $op->concerns($model, $key),
        );

        PHPUnit::assertNotEmpty($matching, $message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(NotificationOperation $operation, array $context): RecordedOperation
    {
        $data = $context['data'] ?? null;

        if ($operation !== NotificationOperation::Send || ! $data instanceof SendNotificationData) {
            return new RecordedOperation($operation, $context);
        }

        // The real send's up-front checks: a send that would throw in production throws here too.
        $type = SendValidation::validate($data, $this->container()->make(TypeRegistry::class))->type;

        try {
            $recipients = $this->container()->make(ResolveRecipientsAction::class)->execute($type, $data->recipients, $data->subject, $data->actor);
        } catch (Throwable) {
            $recipients = [];
        }

        return new RecordedOperation($operation, $context, $recipients, $type->key(), $data->subject);
    }
}
