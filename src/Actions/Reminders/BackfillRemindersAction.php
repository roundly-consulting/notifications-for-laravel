<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Reminders;

use RoundlyConsulting\Notifications\Exceptions\InvalidReminderRuleException;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * Syncs every subject of a type's class — for a type added after the data exists.
 */
final readonly class BackfillRemindersAction
{
    public function __construct(
        private TypeRegistry $types,
        private SyncRemindersAction $sync,
    ) {}

    /**
     * @return int subjects synced
     */
    public function execute(string $type): int
    {
        $definition = $this->types->find($type);
        $class = $definition->subject();

        if ($class === null || $definition->reminders() === []) {
            throw InvalidReminderRuleException::invalid($type, 'the type declares no reminders');
        }

        $count = 0;

        $class::query()->chunkById(200, function ($subjects) use ($type, &$count): void {
            foreach ($subjects as $subject) {
                $this->sync->execute($subject, $type);
                $count++;
            }
        });

        return $count;
    }
}
