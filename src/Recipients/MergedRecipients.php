<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Recipients;

use Illuminate\Database\Eloquent\Model;

/**
 * The union of several specs (duplicates are removed when the send normalises recipients).
 */
final class MergedRecipients extends RecipientSpec
{
    /**
     * @param  list<RecipientSpec>  $specs
     */
    public function __construct(
        public readonly array $specs,
    ) {}

    public function resolve(?Model $subject): array
    {
        $resolved = [];

        foreach ($this->specs as $spec) {
            array_push($resolved, ...$spec->resolve($subject));
        }

        return $resolved;
    }

    public function storable(): bool
    {
        foreach ($this->specs as $spec) {
            if (! $spec->storable()) {
                return false;
            }
        }

        return true;
    }

    public function toStored(): array
    {
        return ['kind' => 'merge', 'specs' => array_map(static fn (RecipientSpec $spec): array => $spec->toStored(), $this->specs)];
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $specs = [];

        foreach (is_array($stored['specs'] ?? null) ? $stored['specs'] : [] as $spec) {
            if (is_array($spec)) {
                $specs[] = RecipientSpec::fromStored($spec);
            }
        }

        return new self($specs);
    }
}
