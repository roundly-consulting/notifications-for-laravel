<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Builders;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\DefinedType;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;

/**
 * A type without a class: `Notifications::define('comment.posted')->…->register()`. Call it from
 * a service provider's `boot()`.
 */
final class TypeBuilder
{
    private ?string $category = null;

    private ?string $label = null;

    private ?string $description = null;

    /** @var list<Channel>|null */
    private ?array $channels = null;

    /** @var array<string, bool> */
    private array $defaults = [];

    private bool $mandatory = false;

    private Priority $priority = Priority::Normal;

    private bool $digestible = false;

    private CarbonInterval|string|null $window = null;

    private ?MailStrategy $mailStrategy = null;

    /** @var class-string<Model>|null */
    private ?string $subject = null;

    private bool $allowsTrashedSubject = false;

    private bool $notifyActor = false;

    /** @var array<string, Closure|null> */
    private array $hooks = [];

    /** @var list<ReminderRule> */
    private array $reminders = [];

    public function __construct(
        private readonly NotificationsManager $manager,
        private readonly string $key,
    ) {}

    public function category(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function label(string $label, ?string $description = null): self
    {
        $this->label = $label;
        $this->description = $description;

        return $this;
    }

    public function channels(Channel ...$channels): self
    {
        $this->channels = array_values($channels);

        return $this;
    }

    /**
     * Off for users who never turned it on (an opt-in channel).
     */
    public function optIn(Channel $channel): self
    {
        $this->defaults[$channel->value] = false;

        return $this;
    }

    public function mandatory(bool $mandatory = true): self
    {
        $this->mandatory = $mandatory;

        return $this;
    }

    public function priority(Priority $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function digestible(bool $digestible = true): self
    {
        $this->digestible = $digestible;

        return $this;
    }

    public function window(CarbonInterval|string|null $window): self
    {
        $this->window = $window;

        return $this;
    }

    public function mailStrategy(MailStrategy $strategy): self
    {
        $this->mailStrategy = $strategy;

        return $this;
    }

    /**
     * @param  class-string<Model>  $class
     */
    public function subject(string $class, bool $allowsTrashed = false): self
    {
        $this->subject = $class;
        $this->allowsTrashedSubject = $allowsTrashed;

        return $this;
    }

    public function notifyActor(bool $notify = true): self
    {
        $this->notifyActor = $notify;

        return $this;
    }

    public function title(Closure $title): self
    {
        return $this->hook('title', $title);
    }

    public function body(Closure $body): self
    {
        return $this->hook('body', $body);
    }

    public function url(Closure $url): self
    {
        return $this->hook('url', $url);
    }

    /**
     * fn (Model $recipient, ?Model $subject): bool — replaces the default Gate check.
     */
    public function access(Closure $check): self
    {
        return $this->hook('access', $check);
    }

    /**
     * fn (?Model $subject): Context|Model|string|null
     */
    public function context(Closure $context): self
    {
        return $this->hook('context', $context);
    }

    /**
     * fn (?Model $subject): bool
     */
    public function sensitive(Closure $sensitive): self
    {
        return $this->hook('sensitive', $sensitive);
    }

    /**
     * fn (MailMessage $message, NotificationPayload $payload): MailMessage
     */
    public function mail(Closure $mail): self
    {
        return $this->hook('mail', $mail);
    }

    /**
     * Interop: fn (Notification $notification): ?Model
     */
    public function subjectFrom(Closure $subjectFrom): self
    {
        return $this->hook('subjectFrom', $subjectFrom);
    }

    public function reminders(ReminderRule ...$rules): self
    {
        $this->reminders = array_values($rules);

        return $this;
    }

    public function make(): DefinedType
    {
        return new DefinedType(
            key: $this->key,
            category: $this->category,
            label: $this->label,
            description: $this->description,
            channels: $this->channels,
            defaults: $this->defaults,
            mandatory: $this->mandatory,
            priority: $this->priority,
            digestible: $this->digestible,
            window: $this->window,
            mailStrategy: $this->mailStrategy,
            subject: $this->subject,
            allowsTrashedSubject: $this->allowsTrashedSubject,
            notifyActor: $this->notifyActor,
            hooks: $this->hooks,
            reminders: $this->reminders,
        );
    }

    public function register(): void
    {
        $this->manager->registerType($this->make());
    }

    private function hook(string $name, Closure $closure): self
    {
        $this->hooks[$name] = $closure;

        return $this;
    }
}
