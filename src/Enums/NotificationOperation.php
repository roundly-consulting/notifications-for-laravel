<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

/**
 * Every state-changing operation the manager performs — what the fake records.
 */
enum NotificationOperation: string
{
    case Send = 'send';
    case MarkRead = 'mark_read';
    case MarkUnread = 'mark_unread';
    case MarkAllRead = 'mark_all_read';
    case Archive = 'archive';
    case Unarchive = 'unarchive';
    case Delete = 'delete';
    case Revoke = 'revoke';
    case SetPreference = 'set_preference';
    case SetCategoryPreference = 'set_category_preference';
    case ResetPreferences = 'reset_preferences';
    case SetDigest = 'set_digest';
    case SetQuietHours = 'set_quiet_hours';
    case SetTimezone = 'set_timezone';
    case UpdatePreferences = 'update_preferences';
    case SyncReminders = 'sync_reminders';
    case ScheduleReminders = 'schedule_reminders';
    case Remind = 'remind';
    case CancelReminders = 'cancel_reminders';
    case Acknowledge = 'acknowledge';
    case FireReminders = 'fire_reminders';
    case BackfillReminders = 'backfill_reminders';
    case SendDigests = 'send_digests';
    case DispatchDeliveries = 'dispatch_deliveries';
    case Sweep = 'sweep';
    case Prune = 'prune';
    case InboxMessage = 'inbox_message';
    case SubjectDeleted = 'subject_deleted';
    case Interop = 'interop';
}
