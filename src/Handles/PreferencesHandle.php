<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Preferences\ResetPreferencesAction;
use RoundlyConsulting\Notifications\Actions\Preferences\SetCategoryPreferenceAction;
use RoundlyConsulting\Notifications\Actions\Preferences\SetChannelPreferenceAction;
use RoundlyConsulting\Notifications\Actions\Preferences\SetDigestPreferenceAction;
use RoundlyConsulting\Notifications\Actions\Preferences\SetNotificationTimezoneAction;
use RoundlyConsulting\Notifications\Actions\Preferences\SetQuietHoursAction;
use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;
use RoundlyConsulting\Notifications\DataTransferObjects\ChannelPreference;
use RoundlyConsulting\Notifications\DataTransferObjects\DigestPreferenceData;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferenceRow;
use RoundlyConsulting\Notifications\DataTransferObjects\PreferencesOverview;
use RoundlyConsulting\Notifications\DataTransferObjects\QuietHours;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\TypeDefinitions;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * One user's preferences: per type and channel, per category, digest, quiet hours and timezone.
 * Muting stops mail only by default — the inbox keeps the item unless
 * `notifications.preferences.inbox_mutable` is on. Mandatory types cannot be muted.
 */
final readonly class PreferencesHandle
{
    public function __construct(
        private NotificationsManager $manager,
        private Model $user,
    ) {}

    public function user(): Model
    {
        return $this->user;
    }

    // Writes -----------------------------------------------------------------------------

    /**
     * Mute a type: mail only, or every mutable channel when inbox muting is on (or one channel).
     */
    public function mute(string $type, ?Channel $channel = null): self
    {
        return $this->setChannels($type, $this->mutableChannels($type, $channel), false);
    }

    /**
     * Clear a type's mute: back to the category and type default.
     */
    public function unmute(string $type, ?Channel $channel = null): self
    {
        return $this->setChannels($type, $channel === null ? Channel::cases() : [$channel], null);
    }

    public function set(string $type, Channel $channel, bool $enabled): self
    {
        return $this->setChannels($type, [$channel], $enabled);
    }

    public function muteCategory(string $category, ?Channel $channel = null): self
    {
        return $this->setCategory($category, $channel === null ? $this->defaultMuteChannels() : [$channel], false);
    }

    public function unmuteCategory(string $category, ?Channel $channel = null): self
    {
        return $this->setCategory($category, $channel === null ? Channel::cases() : [$channel], null);
    }

    /**
     * How often low-priority digestible items arrive as one mail, at an optional local time.
     */
    public function digest(DigestFrequency $frequency, ?string $time = null): self
    {
        $preference = new DigestPreferenceData($frequency, $time);

        $this->manager->perform(
            NotificationOperation::SetDigest,
            SetDigestPreferenceAction::class,
            fn (SetDigestPreferenceAction $action) => $action->execute($this->user, $preference),
            ['user' => $this->user, 'frequency' => $frequency, 'time' => $time],
        );

        return $this;
    }

    /**
     * Local `HH:MM` quiet hours (may cross midnight); `quietHours(null)` clears them.
     */
    public function quietHours(?string $start, ?string $end = null): self
    {
        $quietHours = $start === null ? null : new QuietHours($start, (string) $end);

        $this->manager->perform(
            NotificationOperation::SetQuietHours,
            SetQuietHoursAction::class,
            fn (SetQuietHoursAction $action) => $action->execute($this->user, $quietHours),
            ['user' => $this->user, 'start' => $start, 'end' => $end],
        );

        return $this;
    }

    /**
     * An IANA timezone for quiet hours and digests; null clears it.
     */
    public function timezone(?string $timezone): self
    {
        $this->manager->perform(
            NotificationOperation::SetTimezone,
            SetNotificationTimezoneAction::class,
            fn (SetNotificationTimezoneAction $action) => $action->execute($this->user, $timezone),
            ['user' => $this->user, 'timezone' => $timezone],
        );

        return $this;
    }

    /**
     * Back to every type's defaults (digest, quiet hours and timezone are kept).
     */
    public function reset(): self
    {
        $this->manager->perform(
            NotificationOperation::ResetPreferences,
            ResetPreferencesAction::class,
            fn (ResetPreferencesAction $action) => $action->execute($this->user),
            ['user' => $this->user],
        );

        return $this;
    }

    // Reads ------------------------------------------------------------------------------

    /**
     * Every registered type with this user's effective channels, translated — a settings screen.
     *
     * @return list<PreferenceRow>
     */
    public function matrix(): array
    {
        $map = Preferences::channelMap($this->user);

        return array_map(fn (NotificationType $type): PreferenceRow => new PreferenceRow(
            TypeDefinitions::of($type),
            array_map(fn (Channel $channel): ChannelPreference => new ChannelPreference(
                $channel,
                Preferences::enabled($type, $channel, $this->user, $map),
                $type->enabledByDefault($channel),
                Preferences::locked($type, $channel),
            ), $type->channels()),
        ), app(TypeRegistry::class)->all());
    }

    public function enabled(string $type, Channel $channel): bool
    {
        return Preferences::enabled(app(TypeRegistry::class)->find($type), $channel, $this->user);
    }

    /**
     * Whether the type's mail is off for this user.
     */
    public function muted(string $type): bool
    {
        return ! $this->enabled($type, Channel::Mail);
    }

    /**
     * The whole settings screen at once.
     */
    public function overview(): PreferencesOverview
    {
        return new PreferencesOverview($this->matrix(), $this->digestPreference(), $this->quietHoursPreference(), $this->resolvedTimezone());
    }

    public function digestPreference(): DigestPreferenceData
    {
        return Preferences::digest($this->user);
    }

    /**
     * The user's quiet hours, else the configured default (null when none apply).
     */
    public function quietHoursPreference(): ?QuietHours
    {
        return Preferences::quietHours($this->user);
    }

    /**
     * The timezone quiet hours and digests use for this user.
     */
    public function resolvedTimezone(): string
    {
        return app(ResolvesTimezone::class)->timezone($this->user);
    }

    // Internals --------------------------------------------------------------------------

    /**
     * @param  list<Channel>  $channels
     */
    private function setChannels(string $type, array $channels, ?bool $enabled): self
    {
        $this->manager->perform(
            NotificationOperation::SetPreference,
            SetChannelPreferenceAction::class,
            fn (SetChannelPreferenceAction $action) => $action->execute($this->user, $type, $channels, $enabled),
            ['user' => $this->user, 'type' => $type, 'channels' => $channels, 'enabled' => $enabled],
        );

        return $this;
    }

    /**
     * @param  list<Channel>  $channels
     */
    private function setCategory(string $category, array $channels, ?bool $enabled): self
    {
        $this->manager->perform(
            NotificationOperation::SetCategoryPreference,
            SetCategoryPreferenceAction::class,
            fn (SetCategoryPreferenceAction $action) => $action->execute($this->user, $category, $channels, $enabled),
            ['user' => $this->user, 'category' => $category, 'channels' => $channels, 'enabled' => $enabled],
        );

        return $this;
    }

    /**
     * @return list<Channel>
     */
    private function mutableChannels(string $type, ?Channel $channel): array
    {
        if ($channel !== null) {
            return [$channel];
        }

        $definition = app(TypeRegistry::class)->find($type);

        return array_values(array_filter(
            $this->defaultMuteChannels(),
            static fn (Channel $candidate): bool => in_array($candidate, $definition->channels(), true),
        ));
    }

    /**
     * @return list<Channel>
     */
    private function defaultMuteChannels(): array
    {
        return NotificationsConfig::inboxMutable() ? [Channel::Inbox, Channel::Mail] : [Channel::Mail];
    }
}
