<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Notifications\NotificationsManager;

/**
 * Schedule it daily: `Schedule::command('notifications:prune')->daily();`
 */
final class PruneCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:prune';

    /** @var string */
    protected $description = 'Delete old read and archived inbox items, finished deliveries, digest runs, reminders and receipts';

    public function handle(NotificationsManager $notifications): int
    {
        $report = $notifications->prune();

        $this->info(sprintf(
            'Pruned %d row(s): inbox items %d, deliveries %d, digest runs %d, reminders %d, receipts %d.',
            $report->total(),
            $report->inboxItems,
            $report->deliveries,
            $report->digestRuns,
            $report->reminders,
            $report->receipts,
        ));

        return self::SUCCESS;
    }
}
