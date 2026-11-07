<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * One finding of `notifications:doctor`: `error`, `warning` or `info`.
 */
final readonly class DoctorFinding
{
    public function __construct(
        public string $level,
        public string $message,
    ) {}
}
