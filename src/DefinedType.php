<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;

/**
 * A type built with `Notifications::define($key)` instead of a class. Every unset hook keeps
 * the NotificationType default.
 */
final class DefinedType extends NotificationType
{
    /**
     * @param  list<Channel>|null  $channels
     * @param  array<string, bool>  $defaults  channel value => enabled by default
     * @param  class-string<Model>|null  $subject
     * @param  array<string, Closure|null>  $hooks
     * @param  list<ReminderRule>  $reminders
     */
    public function __construct(
        private readonly string $key,
        private readonly ?string $category = null,
        private readonly ?string $label = null,
        private readonly ?string $description = null,
        private readonly ?array $channels = null,
        private readonly array $defaults = [],
        private readonly bool $mandatory = false,
        private readonly Priority $priority = Priority::Normal,
        private readonly bool $digestible = false,
        private readonly CarbonInterval|string|null $window = null,
        private readonly ?MailStrategy $mailStrategy = null,
        private readonly ?string $subject = null,
        private readonly bool $allowsTrashedSubject = false,
        private readonly bool $notifyActor = false,
        private readonly array $hooks = [],
        private readonly array $reminders = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function title(NotificationPayload $payload): string
    {
        $title = $this->hook('title');

        $value = $title === null ? null : $title($payload);

        return is_string($value) ? $value : $this->label();
    }

    public function category(): string
    {
        return $this->category ?? parent::category();
    }

    public function label(): string
    {
        return $this->label ?? parent::label();
    }

    public function description(): ?string
    {
        return $this->description ?? parent::description();
    }

    public function channels(): array
    {
        return $this->channels ?? parent::channels();
    }

    public function enabledByDefault(Channel $channel): bool
    {
        return $this->defaults[$channel->value] ?? true;
    }

    public function mandatory(): bool
    {
        return $this->mandatory;
    }

    public function priority(): Priority
    {
        return $this->priority;
    }

    public function digestible(): bool
    {
        return $this->digestible;
    }

    public function window(): CarbonInterval|string|null
    {
        return $this->window;
    }

    public function mailStrategy(): MailStrategy
    {
        return $this->mailStrategy ?? parent::mailStrategy();
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    public function subjectFrom(Notification $notification): ?Model
    {
        $hook = $this->hook('subjectFrom');

        if ($hook === null) {
            return null;
        }

        $subject = $hook($notification);

        return $subject instanceof Model ? $subject : null;
    }

    public function canAccess(Model $recipient, ?Model $subject): bool
    {
        $hook = $this->hook('access');

        return $hook === null ? parent::canAccess($recipient, $subject) : (bool) $hook($recipient, $subject);
    }

    public function allowsTrashedSubject(): bool
    {
        return $this->allowsTrashedSubject;
    }

    public function context(?Model $subject): Context|Model|string|null
    {
        $hook = $this->hook('context');

        if ($hook === null) {
            return null;
        }

        $context = $hook($subject);

        return $context instanceof Context || $context instanceof Model || is_string($context) ? $context : null;
    }

    public function notifyActor(): bool
    {
        return $this->notifyActor;
    }

    public function sensitive(?Model $subject): bool
    {
        $hook = $this->hook('sensitive');

        return $hook !== null && (bool) $hook($subject);
    }

    public function body(NotificationPayload $payload): ?string
    {
        $hook = $this->hook('body');

        if ($hook === null) {
            return null;
        }

        $body = $hook($payload);

        return is_string($body) ? $body : null;
    }

    public function url(NotificationPayload $payload): ?string
    {
        $hook = $this->hook('url');

        if ($hook === null) {
            return null;
        }

        $url = $hook($payload);

        return is_string($url) ? $url : null;
    }

    public function mail(MailMessage $message, NotificationPayload $payload): MailMessage
    {
        $hook = $this->hook('mail');

        if ($hook === null) {
            return $message;
        }

        $mail = $hook($message, $payload);

        return $mail instanceof MailMessage ? $mail : $message;
    }

    public function reminders(): array
    {
        return $this->reminders;
    }

    /**
     * Whether a hook (`title`, `access`, `subjectFrom`, …) was given.
     */
    public function defines(string $hook): bool
    {
        return $this->hook($hook) !== null;
    }

    private function hook(string $name): ?Closure
    {
        return $this->hooks[$name] ?? null;
    }
}
