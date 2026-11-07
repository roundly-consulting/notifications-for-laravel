<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Recipients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Notifications\Contracts\HasNotificationOwner;
use RoundlyConsulting\Notifications\Exceptions\NoOwnerException;

/**
 * The subject's owner: `notificationOwner()` when the subject implements HasNotificationOwner,
 * else the named relation.
 */
final class OwnerRecipients extends RecipientSpec
{
    public function __construct(
        public readonly ?string $relation = null,
    ) {}

    public function resolve(?Model $subject): array
    {
        if ($subject === null) {
            throw NoOwnerException::forSubject(null);
        }

        if ($subject instanceof HasNotificationOwner) {
            $owner = $subject->notificationOwner();

            return $owner instanceof Model || $owner === null ? [$owner] : self::models($owner);
        }

        $relation = $this->relation;

        if ($relation === null || ! method_exists($subject, $relation)) {
            throw NoOwnerException::forSubject(class_basename($subject));
        }

        $related = $subject->{$relation}();

        if (! $related instanceof Relation) {
            throw NoOwnerException::forSubject(class_basename($subject));
        }

        $results = $related->getResults();

        if ($results instanceof Model || $results === null) {
            return [$results];
        }

        return is_iterable($results) ? self::models($results) : [];
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<Model|null>
     */
    private static function models(iterable $values): array
    {
        $models = [];

        foreach ($values as $value) {
            $models[] = $value instanceof Model ? $value : null;
        }

        return $models;
    }

    public function storable(): bool
    {
        return true;
    }

    public function toStored(): array
    {
        return ['kind' => 'owner', 'relation' => $this->relation];
    }

    /**
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        $relation = $stored['relation'] ?? null;

        return new self(is_string($relation) ? $relation : null);
    }
}
