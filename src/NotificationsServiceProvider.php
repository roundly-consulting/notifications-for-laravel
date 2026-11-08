<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use RoundlyConsulting\Notifications\Commands\DoctorCommand;
use RoundlyConsulting\Notifications\Commands\InstallCommand;
use RoundlyConsulting\Notifications\Commands\PruneCommand;
use RoundlyConsulting\Notifications\Commands\SweepCommand;
use RoundlyConsulting\Notifications\Commands\SyncRemindersCommand;
use RoundlyConsulting\Notifications\Commands\TypesCommand;
use RoundlyConsulting\Notifications\Contracts\ResolvesGroupMembers;
use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;
use RoundlyConsulting\Notifications\Listeners\ApplyNotificationRules;
use RoundlyConsulting\Notifications\Listeners\RecordInteropOutcome;
use RoundlyConsulting\Notifications\Mail\InboxChannel;
use RoundlyConsulting\Notifications\Options\ChannelPreferences;
use RoundlyConsulting\Notifications\Options\DigestPreference;
use RoundlyConsulting\Notifications\Options\QuietHoursPreference;
use RoundlyConsulting\Notifications\Options\TimezonePreference;
use RoundlyConsulting\Notifications\Recipients\ContractGroupResolver;
use RoundlyConsulting\Notifications\Support\DefaultTimezoneResolver;
use RoundlyConsulting\Notifications\Support\InteropRules;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Options\OptionsManager;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class NotificationsServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('notifications')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasViews()
            ->hasRoutes('notifications.php', 'notifications.routes.enabled', enabledByDefault: false)
            ->hasCommands([
                SweepCommand::class,
                PruneCommand::class,
                TypesCommand::class,
                DoctorCommand::class,
                InstallCommand::class,
                SyncRemindersCommand::class,
            ])
            ->contributesToAbout(static fn (): array => [
                'Types' => (string) count(app(TypeRegistry::class)->all()),
                'Queue' => NotificationsConfig::queueEnabled()
                    ? sprintf('ON (connection %s, queue %s)', NotificationsConfig::queueConnection() === null ? 'DEFAULT' : 'SET', NotificationsConfig::queueName() === null ? 'DEFAULT' : 'SET')
                    : 'OFF (inline)',
                'Routes' => NotificationsConfig::routesEnabled() ? 'ON' : 'OFF',
                'Key type' => NotificationsConfig::keyType()->value,
                'Subject key type' => NotificationsConfig::subjectKeyType()->value,
                'Primary key type' => NotificationsConfig::primaryKeyType()->value,
                'Context key type' => NotificationsConfig::contextKeyType()->value,
                'Digest default' => NotificationsConfig::digestDefault()->value,
                'Interop' => sprintf('%d mapped, %d suppressed', count(app(InteropRules::class)->mapped()), count(app(InteropRules::class)->suppressed())),
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(NotificationsManager::class);
        $this->app->singleton(TypeRegistry::class);
        $this->app->singleton(InteropRules::class);

        $this->bindFromConfig(ResolvesGroupMembers::class, 'notifications.recipients.group_resolver', ContractGroupResolver::class);
        $this->bindFromConfig(ResolvesTimezone::class, 'notifications.timezone.resolver', DefaultTimezoneResolver::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerBlueprintMacros();

        $this->callAfterResolving(OptionsManager::class, static function (OptionsManager $options): void {
            $options->register([
                'notifications.channels' => ChannelPreferences::class,
                'notifications.digest' => DigestPreference::class,
                'notifications.quiet_hours' => QuietHoursPreference::class,
                'notifications.timezone' => TimezonePreference::class,
            ]);
        });

        $this->callAfterResolving(ChannelManager::class, function (ChannelManager $channels, Application $app): void {
            $channels->extend('inbox', fn (): InboxChannel => $app->make(InboxChannel::class));
        });

        $events = $this->app->make(Dispatcher::class);
        $events->listen(NotificationSending::class, [ApplyNotificationRules::class, 'handle']);
        $events->listen(NotificationSent::class, [RecordInteropOutcome::class, 'handle']);
        $events->listen(NotificationFailed::class, [RecordInteropOutcome::class, 'handle']);

        // Interop targets may be types the host defines in its own providers' boot(), which run
        // after this one: validate once everything has booted (and again lazily on first use).
        $this->app->booted(static function (Application $app): void {
            $rules = $app->make(InteropRules::class);

            if ($rules->active()) {
                $rules->validate($app->make(TypeRegistry::class));
            }
        });
    }
}
