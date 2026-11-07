<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Recipients;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Contracts\ResolvesGroupMembers;
use RoundlyConsulting\Notifications\Exceptions\UnstorableRecipientsException;
use RoundlyConsulting\Notifications\Support\ModelRefs;

/**
 * The members of a group (optionally those with one role), through the configured
 * ResolvesGroupMembers. A closure group is evaluated with the subject (reminder rules only).
 */
final class GroupRecipients extends RecipientSpec
{
    public function __construct(
        public readonly Model|Closure|null $group,
        public readonly ?string $role = null,
    ) {}

    public function resolve(?Model $subject): array
    {
        $group = $this->group instanceof Closure ? ($this->group)($subject) : $this->group;

        if (! $group instanceof Model) {
            return [];
        }

        return iterator_to_array(app(ResolvesGroupMembers::class)->members($group, $this->role), false);
    }

    public function storable(): bool
    {
        return ! $this->group instanceof Closure;
    }

    public function toStored(): array
    {
        if ($this->group instanceof Closure) {
            throw UnstorableRecipientsException::closure();
        }

        return ['kind' => 'group', 'group' => $this->group === null ? null : ModelRefs::ref($this->group), 'role' => $this->role];
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $role = $stored['role'] ?? null;

        return new self(ModelRefs::load($stored['group'] ?? null), is_string($role) ? $role : null);
    }
}
