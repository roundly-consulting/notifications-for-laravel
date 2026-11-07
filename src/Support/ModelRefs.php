<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stored model references (`{type, id}`, the morph type) for queued payloads, stored recipient
 * specs and morph columns — internal formats only, never rendered to a client.
 *
 * @internal
 */
final class ModelRefs
{
    /**
     * @return array{type: string, id: int|string}
     */
    public static function ref(Model $model): array
    {
        return ['type' => $model->getMorphClass(), 'id' => self::key($model)];
    }

    /**
     * A model's key as a scalar (every key the package stores is an int or a string).
     */
    public static function key(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : '';
    }

    /**
     * The morph pair as one string: `type:id` — the identity a key digest is built from.
     */
    public static function identity(?Model $model): string
    {
        if ($model === null) {
            return '-';
        }

        $key = $model->getKey();

        return $model->getMorphClass().':'.(is_scalar($key) ? (string) $key : '');
    }

    /**
     * @return class-string<Model>|null
     */
    public static function classFor(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    /**
     * The referenced model, or null when it is gone (soft-deleted ones too unless `$withTrashed`).
     */
    public static function find(?string $type, int|string|null $id, bool $withTrashed = false): ?Model
    {
        if ($type === null || $id === null) {
            return null;
        }

        $class = self::classFor($type);

        if ($class === null) {
            return null;
        }

        $query = $class::query();

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withoutGlobalScopes();
        }

        return $query->whereKey($id)->first();
    }

    /**
     * @param  mixed  $ref  a stored `{type, id}`
     */
    public static function load(mixed $ref): ?Model
    {
        if (! is_array($ref) || ! is_string($ref['type'] ?? null)) {
            return null;
        }

        $id = $ref['id'] ?? null;

        return is_int($id) || is_string($id) ? self::find($ref['type'], $id) : null;
    }
}
