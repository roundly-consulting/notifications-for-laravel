<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Recipients;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Exceptions\UnstorableRecipientsException;

/**
 * Who to notify, resolved at send time with the subject. Built by the `Recipients` factory.
 * Storable specs survive in a reminder row or a queued job and resolve again when they fire.
 */
abstract class RecipientSpec
{
    /**
     * @return list<Model|null>
     */
    abstract public function resolve(?Model $subject): array;

    abstract public function storable(): bool;

    /**
     * The spec as a JSON payload for a reminder row.
     *
     * @return array<string, mixed>
     *
     * @throws UnstorableRecipientsException when the spec holds a closure
     */
    abstract public function toStored(): array;

    /**
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        return match ($stored['kind'] ?? null) {
            'users' => UserRecipients::fromStored($stored),
            'group' => GroupRecipients::fromStored($stored),
            'owner' => OwnerRecipients::fromStored($stored),
            'using' => ResolverRecipients::fromStored($stored),
            'merge' => MergedRecipients::fromStored($stored),
            default => new UserRecipients([]),
        };
    }
}
