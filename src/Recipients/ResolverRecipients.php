<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Recipients;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Contracts\RecipientResolver;
use RoundlyConsulting\Notifications\Exceptions\UnstorableRecipientsException;

/**
 * A host RecipientResolver class with scalar arguments.
 */
final class ResolverRecipients extends RecipientSpec
{
    /**
     * @param  class-string<RecipientResolver>  $resolver
     * @param  array<string, mixed>  $args
     */
    public function __construct(
        public readonly string $resolver,
        public readonly array $args = [],
    ) {}

    public function resolve(?Model $subject): array
    {
        $resolver = app()->make($this->resolver);

        if (! $resolver instanceof RecipientResolver) {
            return [];
        }

        return iterator_to_array($resolver->resolve($subject, $this->args), false);
    }

    public function storable(): bool
    {
        return true;
    }

    public function toStored(): array
    {
        return ['kind' => 'using', 'resolver' => $this->resolver, 'args' => $this->args];
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): RecipientSpec
    {
        $resolver = $stored['resolver'] ?? null;
        $args = $stored['args'] ?? [];

        if (! is_string($resolver) || ! is_subclass_of($resolver, RecipientResolver::class) || ! is_array($args)) {
            return new UserRecipients([]);
        }

        $named = [];

        foreach ($args as $key => $value) {
            $named[(string) $key] = $value;
        }

        return new self($resolver, $named);
    }

    /**
     * Arguments must survive a JSON round trip: scalars and lists of them.
     *
     * @param  array<string, mixed>  $args
     */
    public static function assertStorableArgs(string $resolver, array $args): void
    {
        array_walk_recursive($args, static function (mixed $value) use ($resolver): void {
            if ($value !== null && ! is_scalar($value)) {
                throw UnstorableRecipientsException::resolver(class_basename($resolver));
            }
        });
    }
}
