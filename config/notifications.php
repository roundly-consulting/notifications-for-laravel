<?php

declare(strict_types=1);

use RoundlyConsulting\Notifications\Http\Resources\NotificationActorResource;
use RoundlyConsulting\Notifications\Http\Resources\NotificationContextResource;
use RoundlyConsulting\Notifications\Http\Resources\NotificationSubjectResource;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Models\NotificationReceipt;
use RoundlyConsulting\Notifications\Models\NotificationReminder;
use RoundlyConsulting\Notifications\Recipients\ContractGroupResolver;
use RoundlyConsulting\Notifications\Support\DefaultTimezoneResolver;

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| Every value is read through a strict reader: a typo throws naming the key,
| and a blank env value (`KEY=`) means "not set" — the default applies.
| Durations are ISO-8601 (`PT5M`, `P1D`), times are local `HH:MM`.
|
*/

return [

    // Your notification types (class-strings extending NotificationType). Fluent
    // types are added with Notifications::define() in a service provider.
    'types' => [],

    /*
    | Key types. `key_type` is the key of your users (recipients and actors) and
    | must equal `options.key_type` — preferences are stored per user through
    | options-for-laravel. Subjects and contexts default to `key_type`.
    | `primary_key_type` is the package tables' own id (bigint, uuid or ulid).
    */
    'key_type' => env('NOTIFICATIONS_KEY_TYPE', 'bigint'),
    'subject_key_type' => env('NOTIFICATIONS_SUBJECT_KEY_TYPE'),
    'context_key_type' => env('NOTIFICATIONS_CONTEXT_KEY_TYPE'),
    'primary_key_type' => env('NOTIFICATIONS_PRIMARY_KEY_TYPE', 'bigint'),

    // Swap a model by extending the packaged class and naming the subclass here.
    'models' => [
        'inbox_item' => InboxItem::class,
        'delivery' => NotificationDelivery::class,
        'reminder' => NotificationReminder::class,
        'digest_run' => DigestRun::class,
        'receipt' => NotificationReceipt::class,
    ],

    // Table names. Keep them short: MySQL limits index names to 64 characters.
    'tables' => [
        'inbox_items' => 'notification_inbox_items',
        'deliveries' => 'notification_deliveries',
        'reminders' => 'notification_reminders',
        'digest_runs' => 'notification_digest_runs',
        'receipts' => 'notification_receipts',
    ],

    // Mail, digest and fan-out jobs. Off: they run inline after the commit.
    'queue' => [
        'enabled' => env('NOTIFICATIONS_QUEUE_ENABLED', true),
        'connection' => env('NOTIFICATIONS_QUEUE_CONNECTION'),
        'queue' => env('NOTIFICATIONS_QUEUE_NAME'),
    ],

    // More resolved recipients than `queue_above` are delivered by queued jobs
    // in chunks of `chunk` (each job re-checks access).
    'fan_out' => [
        'queue_above' => 50,
        'chunk' => 100,
    ],

    // The Gate ability the default access check inspects on the subject.
    'access' => [
        'ability' => 'view',
    ],

    // Resolves Recipients::role() / Recipients::members() (a ResolvesGroupMembers).
    'recipients' => [
        'group_resolver' => ContractGroupResolver::class,
    ],

    // How long an idempotency key (->once(), reminder fires) is remembered.
    'idempotency' => [
        'ttl' => 'P30D',
    ],

    'inbox' => [
        'per_page' => 20,
        'max_per_page' => 100,
        'max_data_bytes' => 16384,
        // Hide items whose subject the reader can no longer access (per page).
        'recheck_on_read' => env('NOTIFICATIONS_INBOX_RECHECK', false),
        // What happens to inbox items when their subject is deleted: keep, archive or delete.
        'on_subject_deleted' => 'keep',
    ],

    'preferences' => [
        // Off: muting stops email only — the inbox always keeps the item.
        'inbox_mutable' => false,
        // The cache store for the preference write lock; shared across servers.
        'lock_store' => env('NOTIFICATIONS_LOCK_STORE'),
        'lock_seconds' => 10,
    ],

    'mail' => [
        // leading: the first event mails, later ones in the window fold into it.
        // trailing: one mail after the window with the final count.
        'strategy' => 'leading',
        // A type's sensitive() subjects get a title + link mail only.
        'minimal_for_sensitive' => true,
        // A ResolvesMailAddress class; null routes through the recipient.
        'address_resolver' => null,
        // A URL or route name for the "manage notifications" footer link.
        'preferences_url' => env('NOTIFICATIONS_PREFERENCES_URL'),
        'max_attempts' => 3,
        'backoff' => [60, 300, 900],
        // A mail claimed longer ago than this is failed, never re-sent.
        'lease' => 'PT10M',
    ],

    'quiet_hours' => [
        'enabled' => true,
        // e.g. ['start' => '22:00', 'end' => '07:00'] for users without their own.
        'default' => null,
    ],

    'timezone' => [
        // A ResolvesTimezone class.
        'resolver' => DefaultTimezoneResolver::class,
        // The recipient attribute holding an IANA timezone; blank disables it.
        'attribute' => 'timezone',
    ],

    'digest' => [
        // off, daily or weekly — the frequency of users without a preference.
        'default' => 'off',
        'time' => '08:00',
        'weekly_day' => 'monday',
        'max_items' => 50,
        'lease' => 'PT15M',
    ],

    'reminders' => [
        // A reminder overdue by more than this (after downtime) is skipped.
        'max_lateness' => 'P1D',
        'batch' => 500,
        'lease' => 'PT10M',
    ],

    // Notifications other packages send: map a class to one of your type keys so
    // preferences, mandatory flags, de-dup and quiet hours apply, or suppress it.
    'interop' => [
        'map' => [],
        'suppress' => [],
        // The mapped `database` channel: redirect (into the inbox), keep or both.
        'database' => 'redirect',
        // Hold mapped mail during quiet hours.
        'hold' => true,
        // Extra recipient classes; models using ReceivesNotifications always count.
        'notifiables' => [],
    ],

    // The optional JSON API for the authenticated user's inbox and preferences.
    'routes' => [
        'enabled' => env('NOTIFICATIONS_ROUTES_ENABLED', 'off'),
        'prefix' => 'notifications',
        'middleware' => ['api'],
        'auth_middleware' => ['auth'],
    ],

    // How a host model renders. Point a key at your own JsonResource to add a
    // name or an avatar.
    'resources' => [
        // Receives the actor model.
        'actor' => NotificationActorResource::class,
        // Receives the subject model.
        'subject' => NotificationSubjectResource::class,
        // Receives a ContextReference (key, label, morph type and id).
        'context' => NotificationContextResource::class,
    ],

    // notifications:prune — read and archived inbox items, terminal deliveries,
    // digest runs and reminders older than these.
    'prune' => [
        'inbox_after' => 'P180D',
        'deliveries_after' => 'P30D',
        'digest_runs_after' => 'P90D',
        'reminders_after' => 'P90D',
    ],

];
