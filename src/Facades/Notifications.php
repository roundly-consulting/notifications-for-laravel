<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Testing\NotificationsFake;

/**
 * One letter from Laravel's own `Notification` facade — import this one explicitly; the package
 * registers no global alias.
 *
 * @method static \RoundlyConsulting\Notifications\DataTransferObjects\SendResult send(string $type, \Illuminate\Database\Eloquent\Model|null $subject, \RoundlyConsulting\Notifications\Recipients\RecipientSpec|\Illuminate\Database\Eloquent\Model|\Closure|iterable<mixed> $recipients, array<string, mixed> $data = [], \Illuminate\Database\Eloquent\Model|null $actor = null, \RoundlyConsulting\Notifications\Context|\Illuminate\Database\Eloquent\Model|string|null $context = null, string|null $idempotencyKey = null)
 * @method static \RoundlyConsulting\Notifications\DataTransferObjects\SendResult sendNotification(\RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData $data)
 * @method static \RoundlyConsulting\Notifications\Builders\PendingNotification type(string $type)
 * @method static \RoundlyConsulting\Notifications\Handles\SubjectHandle for(\Illuminate\Database\Eloquent\Model $subject)
 * @method static \RoundlyConsulting\Notifications\Handles\InboxHandle inbox(\Illuminate\Database\Eloquent\Model $recipient)
 * @method static \RoundlyConsulting\Notifications\Handles\PreferencesHandle preferences(\Illuminate\Database\Eloquent\Model $recipient)
 * @method static void register(string ...$types)
 * @method static void registerType(\RoundlyConsulting\Notifications\NotificationType $type)
 * @method static \RoundlyConsulting\Notifications\Builders\TypeBuilder define(string $key)
 * @method static \RoundlyConsulting\Notifications\Handles\TypesAccessor types()
 * @method static \RoundlyConsulting\Notifications\Handles\RemindersAccessor reminders()
 * @method static \RoundlyConsulting\Notifications\Handles\DigestsAccessor digests()
 * @method static \RoundlyConsulting\Notifications\Handles\DeliveriesAccessor deliveries()
 * @method static \RoundlyConsulting\Notifications\Handles\InteropAccessor interop()
 * @method static \RoundlyConsulting\Notifications\Models\InboxItem|null inboxMessage(\Illuminate\Database\Eloquent\Model $recipient, \RoundlyConsulting\Notifications\Mail\InboxMessage $message)
 * @method static \RoundlyConsulting\Notifications\DataTransferObjects\SweepReport sweep(list<string>|null $only = null)
 * @method static \RoundlyConsulting\Notifications\DataTransferObjects\PruneReport prune()
 * @method static \RoundlyConsulting\Notifications\Testing\NotificationsFake passthrough(bool $passthrough = true)
 * @method static list<\RoundlyConsulting\Notifications\Testing\RecordedOperation> recorded(\RoundlyConsulting\Notifications\Enums\NotificationOperation|null $operation = null)
 * @method static void assertSent(string $type, \Illuminate\Database\Eloquent\Model|\Closure|null $to = null, \Illuminate\Database\Eloquent\Model|null $about = null, int|null $times = null)
 * @method static void assertNotSent(string $type)
 * @method static void assertNothingSent()
 * @method static void assertMarkedRead(\Illuminate\Database\Eloquent\Model|null $recipient = null)
 * @method static void assertArchived(\Illuminate\Database\Eloquent\Model|null $recipient = null)
 * @method static void assertPreferenceSet(\Illuminate\Database\Eloquent\Model $user, string $type, \RoundlyConsulting\Notifications\Enums\Channel $channel, bool $enabled)
 * @method static void assertMuted(\Illuminate\Database\Eloquent\Model $user, string $type)
 * @method static void assertPreferencesUpdated(\Illuminate\Database\Eloquent\Model $user, \Closure|null $where = null)
 * @method static void assertRemindersSynced(\Illuminate\Database\Eloquent\Model $subject)
 * @method static void assertReminderScheduled(\Illuminate\Database\Eloquent\Model $subject, string $type)
 * @method static void assertRemindersCancelled(\Illuminate\Database\Eloquent\Model $subject)
 * @method static void assertAcknowledged(\Illuminate\Database\Eloquent\Model $subject, string|null $type = null)
 * @method static void assertRevoked(\Illuminate\Database\Eloquent\Model $subject, \Illuminate\Database\Eloquent\Model|null $recipient = null)
 * @method static void assertSwept()
 *
 * @see NotificationsManager
 */
final class Notifications extends Facade
{
    /**
     * Swap the manager for a record-only fake — behind the facade and in the container, so an
     * injected NotificationsManager is faked too.
     */
    public static function fake(): NotificationsFake
    {
        $fake = app(NotificationsFake::class);

        self::swap($fake);
        app()->instance(NotificationsManager::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return NotificationsManager::class;
    }
}
