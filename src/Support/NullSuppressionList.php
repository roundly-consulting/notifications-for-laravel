<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use RoundlyConsulting\Notifications\Contracts\SuppressionList;

/**
 * The default suppression list: suppresses nothing.
 */
final class NullSuppressionList implements SuppressionList
{
    public function isSuppressed(string $address): bool
    {
        return false;
    }
}
