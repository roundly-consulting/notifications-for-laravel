<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Support\Durations;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Support\Windows;

/**
 * Lists the registered types.
 */
final class TypesCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:types';

    /** @var string */
    protected $description = 'List the registered notification types';

    public function handle(TypeRegistry $types): int
    {
        $rows = array_map(static function (NotificationType $type): array {
            $window = Windows::of($type);
            $flags = array_filter([
                $type->mandatory() ? 'mandatory' : null,
                $type->digestible() ? 'digestible' : null,
            ]);

            return [
                $type->key(),
                $type->category(),
                implode(', ', array_map(static fn (Channel $channel): string => $channel->value, $type->channels())),
                $type->priority()->value,
                implode(', ', $flags),
                $window === null ? '-' : Durations::toIso($window),
                (string) count($type->reminders()),
            ];
        }, $types->all());

        if ($rows === []) {
            $this->warn('No notification types are registered.');

            return self::SUCCESS;
        }

        $this->table(['Key', 'Category', 'Channels', 'Priority', 'Flags', 'Window', 'Reminder rules'], $rows);

        return self::SUCCESS;
    }
}
