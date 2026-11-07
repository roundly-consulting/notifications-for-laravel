<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use RoundlyConsulting\Notifications\DataTransferObjects\CategoryDefinition;
use RoundlyConsulting\Notifications\DataTransferObjects\TypeDefinition;
use RoundlyConsulting\Notifications\NotificationType;
use RoundlyConsulting\Notifications\Support\TypeDefinitions;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * The registered types, for a preferences UI: `Notifications::types()->all()`.
 */
final readonly class TypesAccessor
{
    public function __construct(
        private TypeRegistry $registry,
    ) {}

    /**
     * @return list<TypeDefinition>
     */
    public function all(): array
    {
        return array_map(TypeDefinitions::of(...), $this->registry->all());
    }

    /**
     * @return list<CategoryDefinition>
     */
    public function categories(): array
    {
        $keys = array_values(array_unique(array_map(static fn (NotificationType $type): string => $type->category(), $this->registry->all())));

        return array_map(TypeDefinitions::category(...), $keys);
    }

    public function find(string $key): TypeDefinition
    {
        return TypeDefinitions::of($this->registry->find($key));
    }

    public function has(string $key): bool
    {
        return $this->registry->has($key);
    }

    /**
     * The type itself (its hooks), for host code that renders it.
     */
    public function get(string $key): NotificationType
    {
        return $this->registry->find($key);
    }
}
