<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications;

use Illuminate\Database\Eloquent\Model;

/**
 * Where an inbox item belongs, for filtering: a model (`Context::of($company)`) and/or a string
 * key with a display label (`Context::key('billing', 'Billing')`). Never a tenancy boundary.
 */
final readonly class Context
{
    private function __construct(
        public ?Model $model,
        public ?string $key,
        public ?string $label,
    ) {}

    /**
     * A model context; the label defaults to its `notificationContextLabel()`, else its `name`.
     */
    public static function of(Model $model, ?string $label = null): self
    {
        return new self($model, null, $label);
    }

    /**
     * A context without a model.
     */
    public static function key(string $key, ?string $label = null): self
    {
        return new self(null, $key, $label ?? $key);
    }
}
