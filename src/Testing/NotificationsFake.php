<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Notifications\Actions\Sending\ResolveRecipientsAction;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferencesUpdate;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\NotificationsManager;
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

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

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

        $this->recorded[] = $this->record($operation, $context);

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
     * @param  Model|(Closure(Model): bool)|null  $to
     */
    public function assertSent(string $type, Model|Closure|null $to = null, ?Model $about = null, ?int $times = null): void
    {
        $matching = array_filter($this->recorded(NotificationOperation::Send), static function (RecordedOperation $send) use ($type, $to, $about): bool {
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
        });

        if ($times !== null) {
            PHPUnit::assertCount($times, $matching, sprintf('Expected [%s] to be sent %d time(s), but it was sent %d time(s).', $type, $times, count($matching)));

            return;
        }

        PHPUnit::assertNotEmpty($matching, sprintf('Expected [%s] to be sent%s, but no send matched.', $type, $to === null ? '' : ' to the recipient'));
    }

    public function assertNotSent(string $type): void
    {
        $sent = array_filter($this->recorded(NotificationOperation::Send), static fn (RecordedOperation $send): bool => $send->type === $type);

        PHPUnit::assertEmpty($sent, sprintf('Expected [%s] not to be sent, but it was sent %d time(s).', $type, count($sent)));
    }

    public function assertNothingSent(): void
    {
        $sent = $this->recorded(NotificationOperation::Send);

        PHPUnit::assertEmpty($sent, sprintf('Expected nothing to be sent, but %d send(s) were recorded.', count($sent)));
    }

    // Inbox ------------------------------------------------------------------------------

    public function assertMarkedRead(?Model $recipient = null): void
    {
        $this->assertRecorded([NotificationOperation::MarkRead, NotificationOperation::MarkAllRead], 'recipient', $recipient, 'Expected an inbox item to be marked read, but none was.');
    }

    public function assertArchived(?Model $recipient = null): void
    {
        $this->assertRecorded([NotificationOperation::Archive], 'recipient', $recipient, 'Expected an inbox item to be archived, but none was.');
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
        $this->assertRecorded([NotificationOperation::SyncReminders], 'subject', $subject, 'Expected the reminders of the subject to be synced, but they were not.');
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

    public function assertRemindersCancelled(Model $subject): void
    {
        $this->assertRecorded([NotificationOperation::CancelReminders], 'subject', $subject, 'Expected the reminders of the subject to be cancelled, but they were not.');
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

    // Internals --------------------------------------------------------------------------

    /**
     * @param  list<NotificationOperation>  $operations
     */
    private function assertRecorded(array $operations, string $key, ?Model $model, string $message): void
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

        $registry = $this->container->make(TypeRegistry::class);
        $type = $registry->get($data->type);
        $recipients = [];

        if ($type !== null) {
            try {
                $recipients = $this->container->make(ResolveRecipientsAction::class)->execute($type, $data->recipients, $data->subject, $data->actor);
            } catch (Throwable) {
                $recipients = [];
            }
        }

        return new RecordedOperation($operation, $context, $recipients, $type?->key() ?? $data->type, $data->subject);
    }
}
