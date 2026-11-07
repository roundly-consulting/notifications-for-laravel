<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;

/**
 * One operation the fake recorded: what was asked, and — for a send — who it would reach.
 */
final readonly class RecordedOperation
{
    /**
     * @param  array<string, mixed>  $context
     * @param  list<Model>  $recipients  resolved recipients of a send
     */
    public function __construct(
        public NotificationOperation $operation,
        public array $context,
        public array $recipients = [],
        public ?string $type = null,
        public ?Model $subject = null,
    ) {}

    public function concerns(?Model $model, string $key): bool
    {
        if ($model === null) {
            return true;
        }

        $value = $this->context[$key] ?? null;

        return $value instanceof Model && $value->is($model);
    }
}
