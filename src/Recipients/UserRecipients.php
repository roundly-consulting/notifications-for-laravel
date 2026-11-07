<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Recipients;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Support\ModelRefs;

/**
 * Explicit recipient models.
 */
final class UserRecipients extends RecipientSpec
{
    /**
     * @param  list<Model>  $models
     */
    public function __construct(
        public readonly array $models,
    ) {}

    public function resolve(?Model $subject): array
    {
        return $this->models;
    }

    public function storable(): bool
    {
        return true;
    }

    public function toStored(): array
    {
        return ['kind' => 'users', 'refs' => array_map(ModelRefs::ref(...), $this->models)];
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $refs = is_array($stored['refs'] ?? null) ? $stored['refs'] : [];

        return new self(array_values(array_filter(array_map(ModelRefs::load(...), $refs))));
    }
}
