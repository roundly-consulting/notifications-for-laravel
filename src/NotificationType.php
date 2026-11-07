<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\MailStrategy;
use RoundlyConsulting\Notifications\Enums\Priority;
use RoundlyConsulting\Notifications\Exceptions\MissingAccessCheckException;
use RoundlyConsulting\Notifications\Reminders\ReminderRule;
use RoundlyConsulting\Notifications\Support\Messages;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * A notification type: a stable key plus the rules the package applies to it. Extend it with a
 * small `final` class and list it in `config('notifications.types')`, or build one fluently with
 * `Notifications::define($key)`. Only `key()` and `title()` are required.
 */
abstract class NotificationType
{
    /**
     * The stable key, e.g. `request.awaiting_approval` (lowercase letters, digits, underscores,
     * dot-separated, at most 100 characters).
     */
    abstract public function key(): string;

    /**
     * The inbox title (and mail subject), in the current locale.
     */
    abstract public function title(NotificationPayload $payload): string;

    /**
     * Groups the preferences matrix and inbox filters.
     */
    public function category(): string
    {
        return 'general';
    }

    /**
     * The `notifications.types.<key>.label` line of the host's translations, else the key in
     * headline case.
     */
    public function label(): string
    {
        $line = 'notifications.types.'.$this->key().'.label';

        return Lang::has($line) && is_string($label = Lang::get($line))
            ? $label
            : Str::headline(str_replace('.', ' ', $this->key()));
    }

    public function description(): ?string
    {
        $line = 'notifications.types.'.$this->key().'.description';

        return Lang::has($line) && is_string($description = Lang::get($line)) ? $description : null;
    }

    /**
     * @return list<Channel>
     */
    public function channels(): array
    {
        return [Channel::Inbox, Channel::Mail];
    }

    /**
     * Whether a channel is on for users who never changed it.
     */
    public function enabledByDefault(Channel $channel): bool
    {
        return true;
    }

    /**
     * Mandatory types ignore every mute; a write that would mute one throws.
     */
    public function mandatory(): bool
    {
        return false;
    }

    public function priority(): Priority
    {
        return Priority::Normal;
    }

    /**
     * Low priority and digestible: the mail joins the user's digest when they have one.
     */
    public function digestible(): bool
    {
        return false;
    }

    /**
     * The collapse and de-dup window (ISO-8601 or relative, at most one day). Null: every event
     * is its own inbox item and mail.
     */
    public function window(): CarbonInterval|string|null
    {
        return null;
    }

    public function mailStrategy(): MailStrategy
    {
        return NotificationsConfig::mailStrategy();
    }

    /**
     * The subject model class this type is about, or null for none.
     *
     * @return class-string<Model>|null
     */
    public function subject(): ?string
    {
        return null;
    }

    /**
     * Interop only: the subject of another package's notification mapped to this type.
     */
    public function subjectFrom(Notification $notification): ?Model
    {
        return null;
    }

    /**
     * May this recipient still see the subject? By default the Gate ability
     * `notifications.access.ability` (`view`) on the subject, for the recipient.
     */
    public function canAccess(Model $recipient, ?Model $subject): bool
    {
        if ($subject === null) {
            return true;
        }

        $ability = NotificationsConfig::accessAbility();

        if (Gate::getPolicyFor($subject) === null && ! Gate::has($ability)) {
            throw MissingAccessCheckException::forType($this->key(), class_basename($subject), $ability);
        }

        return Gate::forUser($recipient)->inspect($ability, $subject)->allowed();
    }

    /**
     * Whether a soft-deleted subject still counts as present.
     */
    public function allowsTrashedSubject(): bool
    {
        return false;
    }

    /**
     * The subject line of a minimal mail (sensitive subjects).
     */
    public function minimalTitle(): string
    {
        return Messages::get('mail.minimal_subject', ['app' => Messages::appName()]);
    }

    /**
     * The default context when the sender gives none.
     */
    public function context(?Model $subject): Context|Model|string|null
    {
        return null;
    }

    /**
     * Whether the actor is notified of their own action.
     */
    public function notifyActor(): bool
    {
        return false;
    }

    /**
     * Sensitive subjects get a minimal mail: a generic subject, one line and the link.
     */
    public function sensitive(?Model $subject): bool
    {
        return false;
    }

    public function body(NotificationPayload $payload): ?string
    {
        return null;
    }

    /**
     * The action link (absolute http(s) only; anything else is never rendered).
     */
    public function url(NotificationPayload $payload): ?string
    {
        return null;
    }

    public function actionText(NotificationPayload $payload): string
    {
        return Messages::get('mail.view');
    }

    public function digestLine(NotificationPayload $payload): string
    {
        return $this->title($payload);
    }

    /**
     * The last hook to tweak the mail (not called for a minimal mail).
     */
    public function mail(MailMessage $message, NotificationPayload $payload): MailMessage
    {
        return $message;
    }

    /**
     * What the inbox resource exposes as `data`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function publicData(array $data): array
    {
        return $data;
    }

    /**
     * @return list<ReminderRule>
     */
    public function reminders(): array
    {
        return [];
    }
}
