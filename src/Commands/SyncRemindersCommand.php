<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Backfills reminders: syncs every subject of a type (or of every type with reminder rules).
 */
final class SyncRemindersCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:reminders:sync {type? : A type key; every type with reminder rules when omitted}';

    /** @var string */
    protected $description = 'Schedule reminders for subjects that existed before their type was added';

    public function handle(NotificationsManager $notifications, TypeRegistry $types): int
    {
        $type = $this->argument('type');

        $keys = is_string($type) && $type !== ''
            ? [$type]
            : array_map(static fn (NotificationType $definition): string => $definition->key(), array_values(array_filter(
                $types->all(),
                static fn (NotificationType $definition): bool => $definition->reminders() !== [],
            )));

        foreach ($keys as $key) {
            $count = $notifications->reminders()->backfill($key);
            $this->info(sprintf('%s: %d subject(s) synced.', $key, $count));
        }

        return self::SUCCESS;
    }
}
