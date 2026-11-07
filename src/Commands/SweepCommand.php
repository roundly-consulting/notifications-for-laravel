<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Notifications\Actions\Maintenance\SweepAction;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * Schedule it every minute: `Schedule::command('notifications:sweep')->everyMinute()->withoutOverlapping(10)->onOneServer();`
 */
final class SweepCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:sweep {--only= : A comma-separated subset of claims, deliveries, digests, reminders, escalations}';

    /** @var string */
    protected $description = 'Release due mail, send due digests, fire due reminders and escalations, expire stale claims';

    public function handle(NotificationsManager $notifications): int
    {
        $only = $this->option('only');
        $parts = is_string($only) && trim($only) !== '' ? array_map('trim', explode(',', $only)) : null;

        if ($parts !== null && array_diff($parts, SweepAction::PARTS) !== []) {
            $this->error('Unknown part. Use: '.implode(', ', SweepAction::PARTS).'.');

            return self::INVALID;
        }

        $report = $notifications->sweep($parts);

        $this->info(sprintf(
            'Deliveries: %d, digests: %d, reminders: %d, escalations: %d, expired claims: %d.',
            $report->deliveries,
            $report->digests,
            $report->reminders,
            $report->escalations,
            $report->expired,
        ));

        return self::SUCCESS;
    }
}
