<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A host resolver for Recipients::using(): given the subject and scalar arguments, the models to
 * notify. Resolved from the container, so it may take constructor dependencies.
 */
interface RecipientResolver
{
    /**
     * @param  array<string, mixed>  $args
     * @return iterable<Model>
     */
    public function resolve(?Model $subject, array $args): iterable;
}
