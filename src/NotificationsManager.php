<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications;

use Closure;
use Illuminate\Container\Container as CurrentContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use LogicException;
use RoundlyConsulting\Notifications\Actions\Inbox\RecordInboxMessageAction;
use RoundlyConsulting\Notifications\Actions\Interop\ApplyInteropRulesAction;
use RoundlyConsulting\Notifications\Actions\Maintenance\PruneNotificationsAction;
use RoundlyConsulting\Notifications\Actions\Maintenance\SweepAction;
use RoundlyConsulting\Notifications\Actions\Sending\SendNotificationAction;
use RoundlyConsulting\Notifications\Builders\PendingNotification;
use RoundlyConsulting\Notifications\Builders\TypeBuilder;
use RoundlyConsulting\Notifications\DataTransferObjects\PruneReport;
use RoundlyConsulting\Notifications\DataTransferObjects\SendNotificationData;
use RoundlyConsulting\Notifications\DataTransferObjects\SendResult;
use RoundlyConsulting\Notifications\DataTransferObjects\SweepReport;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Handles\DeliveriesAccessor;
use RoundlyConsulting\Notifications\Handles\DigestsAccessor;
use RoundlyConsulting\Notifications\Handles\InboxHandle;
use RoundlyConsulting\Notifications\Handles\InteropAccessor;
use RoundlyConsulting\Notifications\Handles\PreferencesHandle;
use RoundlyConsulting\Notifications\Handles\RemindersAccessor;
use RoundlyConsulting\Notifications\Handles\SubjectHandle;
use RoundlyConsulting\Notifications\Handles\TypesAccessor;
use RoundlyConsulting\Notifications\Handles\UnsubscribeAccessor;
use RoundlyConsulting\Notifications\Mail\InboxMessage;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\Recipients\RecipientSpec;
use RoundlyConsulting\Notifications\Support\InteropRules;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * The package's public API — the root behind the Notifications facade, injectable by its own
 * class-string. Every method is thin: it resolves an action through the container (so host
 * overrides and the fake apply) or hands back a handle whose mutations come back through
 * {@see self::perform()}.
 *
 * Not final on purpose: NotificationsFake extends it, so code that injects this class still
 * type-checks under `Notifications::fake()`.
 *
 * Holds no container: it is a singleton a host may resolve at boot, and under Octane each request
 * runs in its own sandbox container, so every call resolves from the current one.
 */
class NotificationsManager
{
    /**
     * Notify recipients of one event: inbox and mail, preferences, de-dup, quiet hours, digests
     * and access are applied per recipient.
     *
     * @param  RecipientSpec|Model|Closure|iterable<mixed>  $recipients
     * @param  array<string, mixed>  $data
     */
    public function send(
        string $type,
        ?Model $subject,
        RecipientSpec|Model|Closure|iterable $recipients,
        array $data = [],
        ?Model $actor = null,
        Context|Model|string|null $context = null,
        ?string $idempotencyKey = null,
    ): SendResult {
        return $this->sendNotification(new SendNotificationData(
            type: $type,
            subject: $subject,
            recipients: Recipients::from($recipients),
            data: $data,
            actor: $actor,
            context: $context,
            idempotencyKey: $idempotencyKey,
        ));
    }

    /**
     * The send, as a DTO (what the fluent builder and the raw action use).
     */
    public function sendNotification(SendNotificationData $data): SendResult
    {
        return $this->perform(
            NotificationOperation::Send,
            SendNotificationAction::class,
            static fn (SendNotificationAction $action): SendResult => $action->execute($data),
            ['data' => $data],
        ) ?? new SendResult($this->container()->make(TypeRegistry::class)->keyOf($data->type));
    }

    /**
     * The fluent form of a send: `type($key)->about($subject)->to($recipients)->send()`.
     */
    public function type(string $type): PendingNotification
    {
        return new PendingNotification($this, $type);
    }

    /**
     * Everything about one subject: sends, reminders, acknowledgement, revocation.
     */
    public function for(Model $subject): SubjectHandle
    {
        return new SubjectHandle($this, $subject);
    }

    public function inbox(Model $recipient): InboxHandle
    {
        return new InboxHandle($this, $recipient);
    }

    public function preferences(Model $recipient): PreferencesHandle
    {
        return new PreferencesHandle($this, $recipient);
    }

    /**
     * @param  class-string<NotificationType>  ...$types
     */
    public function register(string ...$types): void
    {
        $this->container()->make(TypeRegistry::class)->register(...$types);
    }

    /**
     * Register a type instance (what `define()->register()` calls).
     */
    public function registerType(NotificationType $type): void
    {
        $this->container()->make(TypeRegistry::class)->add($type);
    }

    /**
     * A type without a class, built fluently and registered with `->register()`.
     */
    public function define(string $key): TypeBuilder
    {
        return new TypeBuilder($this, $key);
    }

    public function types(): TypesAccessor
    {
        return new TypesAccessor($this->container()->make(TypeRegistry::class));
    }

    public function reminders(): RemindersAccessor
    {
        return new RemindersAccessor($this);
    }

    public function digests(): DigestsAccessor
    {
        return new DigestsAccessor($this);
    }

    public function deliveries(): DeliveriesAccessor
    {
        return new DeliveriesAccessor($this);
    }

    /**
     * One-click unsubscribe links (List-Unsubscribe): build one, read one, redeem one.
     */
    public function unsubscribe(): UnsubscribeAccessor
    {
        return new UnsubscribeAccessor($this);
    }

    public function interop(): InteropAccessor
    {
        return new InteropAccessor($this->container()->make(InteropRules::class));
    }

    /**
     * Write a Laravel `inbox` channel message into a recipient's inbox (what the channel calls).
     */
    public function inboxMessage(Model $recipient, InboxMessage $message): ?InboxItem
    {
        return $this->perform(
            NotificationOperation::InboxMessage,
            RecordInboxMessageAction::class,
            static fn (RecordInboxMessageAction $action): ?InboxItem => $action->execute($recipient, $message),
            ['recipient' => $recipient, 'type' => $message->type, 'subject' => $message->subject],
        );
    }

    /**
     * Everything time-driven: due mail, digests, reminders and escalations, stale claims.
     *
     * @param  list<string>|null  $only  claims, deliveries, digests, reminders, escalations
     */
    public function sweep(?array $only = null): SweepReport
    {
        return $this->perform(
            NotificationOperation::Sweep,
            SweepAction::class,
            static fn (SweepAction $action): SweepReport => $action->execute($only),
            ['only' => $only],
        ) ?? new SweepReport;
    }

    public function prune(): PruneReport
    {
        return $this->perform(
            NotificationOperation::Prune,
            PruneNotificationsAction::class,
            static fn (PruneNotificationsAction $action): PruneReport => $action->execute(),
        ) ?? new PruneReport;
    }

    /**
     * What the interop listener asks per notifiable and channel: false cancels the channel.
     *
     * @internal
     */
    public function applyInterop(object $notifiable, Notification $notification, string $channel): ?bool
    {
        return $this->perform(
            NotificationOperation::Interop,
            ApplyInteropRulesAction::class,
            static fn (ApplyInteropRulesAction $action): ?bool => $action->execute($notifiable, $notification, $channel),
            ['notifiable' => $notifiable, 'notification' => $notification, 'channel' => $channel],
        );
    }

    /**
     * The current container (the request's sandbox under Octane), never the one at boot.
     *
     * @internal
     */
    public function container(): Container
    {
        return CurrentContainer::getInstance();
    }

    /**
     * Run one state-changing operation. Every handle, accessor, builder, channel and model trait
     * funnels its terminal call through here, resolving the action from the container, so host
     * overrides apply and the fake records the call. The fake returns null for operations it
     * does not run.
     *
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
        $instance = $this->container()->make($action);

        if (! $instance instanceof $action) {
            throw new LogicException(sprintf('The container resolved [%s] to another class.', class_basename($action)));
        }

        return $execute($instance);
    }
}
