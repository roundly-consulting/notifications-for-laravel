<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Resolvers;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Contracts\RecipientResolver;
use RuntimeException;

/**
 * A resolver whose backing service is down.
 */
final class ThrowingResolver implements RecipientResolver
{
    public function resolve(?Model $subject, array $args): iterable
    {
        throw new RuntimeException('directory down');
    }
}
