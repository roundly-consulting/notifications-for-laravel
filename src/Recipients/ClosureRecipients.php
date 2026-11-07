<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Recipients;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Exceptions\UnstorableRecipientsException;
use RoundlyConsulting\Notifications\Recipients;

/**
 * Recipients computed from the subject by a closure — only inside code-defined reminder rules,
 * which re-create it from the type at fire time. It can never be stored.
 */
final class ClosureRecipients extends RecipientSpec
{
    public function __construct(
        public readonly Closure $resolver,
    ) {}

    public function resolve(?Model $subject): array
    {
        $value = ($this->resolver)($subject);

        if ($value instanceof RecipientSpec || $value instanceof Model || $value instanceof Closure || is_iterable($value)) {
            return Recipients::from($value)->resolve($subject);
        }

        return [];
    }

    public function storable(): bool
    {
        return false;
    }

    public function toStored(): array
    {
        throw UnstorableRecipientsException::closure();
    }
}
