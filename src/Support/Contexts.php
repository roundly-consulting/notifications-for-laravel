<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Context;
use RoundlyConsulting\Notifications\Contracts\HasNotificationContext;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextReference;
use RoundlyConsulting\Notifications\Exceptions\InvalidNotificationDataException;

/**
 * Turns a context (a Context, a model or a plain string) into the stored reference. A model's key
 * is `{alias}:{id}` where alias is the morph-map alias, else the snake-cased basename — never a
 * class name, because the key goes over the wire.
 *
 * @internal
 */
final class Contexts
{
    public static function resolve(Context|Model|string|null $context): ?ContextReference
    {
        if ($context === null) {
            return null;
        }

        if (is_string($context)) {
            return self::fromString($context, $context);
        }

        if ($context instanceof Model) {
            return self::fromModel($context, null);
        }

        if ($context->model !== null) {
            return self::fromModel($context->model, $context->label);
        }

        return self::fromString((string) $context->key, $context->label ?? (string) $context->key);
    }

    /**
     * The filter key for a model or a key string (`in($company)` / `in('company:3')`).
     */
    public static function keyOf(Model|string $context): string
    {
        if (is_string($context)) {
            return $context;
        }

        $id = $context->getKey();

        return self::alias($context).':'.(is_scalar($id) ? (string) $id : '');
    }

    public static function alias(Model $model): string
    {
        $alias = Relation::getMorphAlias($model::class);

        return is_string($alias) && $alias !== $model::class ? $alias : Str::snake(class_basename($model));
    }

    private static function fromModel(Model $model, ?string $label): ContextReference
    {
        KeyTypes::assert($model, 'context');

        $id = ModelRefs::key($model);

        return new ContextReference(
            key: self::keyOf($model),
            label: Str::limit($label ?? self::label($model), 250, ''),
            type: $model->getMorphClass(),
            id: $id,
        );
    }

    private static function fromString(string $key, string $label): ContextReference
    {
        if ($key === '' || str_contains($key, '\\') || strlen($key) > 191) {
            throw InvalidNotificationDataException::invalidContext();
        }

        return new ContextReference($key, Str::limit($label, 250, ''));
    }

    private static function label(Model $model): string
    {
        if ($model instanceof HasNotificationContext) {
            return $model->notificationContextLabel();
        }

        $attributes = $model->getAttributes();

        if (array_key_exists('name', $attributes) && is_string($attributes['name']) && $attributes['name'] !== '') {
            return $attributes['name'];
        }

        $id = $model->getKey();

        return class_basename($model).' '.(is_scalar($id) ? (string) $id : '');
    }
}
