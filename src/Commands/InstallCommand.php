<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * Publishes the config and the migrations (refusing when a table already exists) and prints the
 * schedule lines and the trait snippets.
 */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:install';

    /** @var string */
    protected $description = 'Publish the notifications config and migrations and print the next steps';

    public function handle(): int
    {
        foreach (['inbox_items', 'deliveries', 'reminders', 'digest_runs', 'receipts'] as $table) {
            if (Schema::hasTable(NotificationsConfig::table($table))) {
                $this->error(sprintf('The table [%s] already exists: the migrations were published before.', NotificationsConfig::table($table)));

                return self::FAILURE;
            }
        }

        $this->call('vendor:publish', ['--tag' => 'notifications-config']);
        $this->call('vendor:publish', ['--tag' => 'notifications-migrations']);

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. php artisan migrate (options-for-laravel\'s migration too, for preferences)');
        $this->line('  2. Schedule the sweep and the prune (routes/console.php):');
        $this->line("     Schedule::command('notifications:sweep')->everyMinute()->withoutOverlapping(10)->onOneServer();");
        $this->line("     Schedule::command('notifications:prune')->daily();");
        $this->line('  3. Add ReceivesNotifications (and Notifiable) to your User, HasNotificationReminders to reminder subjects.');
        $this->line('  4. List your types in config/notifications.php (types) and run php artisan notifications:doctor.');

        return self::SUCCESS;
    }
}
