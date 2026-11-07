<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Notifications\Support\Doctor;

/**
 * Checks the setup: key types, queue, lock store, quiet hours, access policies, interop mappings
 * and the user model.
 */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'notifications:doctor';

    /** @var string */
    protected $description = 'Check the notifications setup';

    public function handle(): int
    {
        $errors = 0;

        foreach (Doctor::run() as $finding) {
            match ($finding->level) {
                'error' => $this->error('✗ '.$finding->message),
                'warning' => $this->warn('! '.$finding->message),
                default => $this->line('· '.$finding->message),
            };

            $errors += $finding->level === 'error' ? 1 : 0;
        }

        if ($errors === 0) {
            $this->info('No problems found.');
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
