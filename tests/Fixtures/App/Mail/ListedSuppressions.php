<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Mail;

use RoundlyConsulting\Notifications\Contracts\SuppressionList;

/**
 * A host suppression list: the addresses in `$suppressed`, case-insensitively.
 */
final class ListedSuppressions implements SuppressionList
{
    /** @var list<string> */
    public static array $suppressed = [];

    /** @var list<string> */
    public static array $asked = [];

    public function isSuppressed(string $address): bool
    {
        self::$asked[] = $address;

        return in_array(strtolower($address), array_map(strtolower(...), self::$suppressed), true);
    }
}
