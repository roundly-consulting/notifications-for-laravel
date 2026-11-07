<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\NotificationType;

/**
 * Re-reads a subject before an access check: gone (deleted, or soft-deleted unless the type
 * allows it) is null.
 *
 * @internal
 */
final class Subjects
{
    public static function fresh(NotificationType $type, ?Model $subject): ?Model
    {
        if ($subject === null) {
            return null;
        }

        return self::present($type, $subject->fresh());
    }

    public static function find(NotificationType $type, ?string $morphType, int|string|null $id): ?Model
    {
        return self::present($type, ModelRefs::find($morphType, $id, withTrashed: true));
    }

    private static function present(NotificationType $type, ?Model $subject): ?Model
    {
        if ($subject === null) {
            return null;
        }

        if (method_exists($subject, 'trashed') && $subject->trashed() && ! $type->allowsTrashedSubject()) {
            return null;
        }

        return $subject;
    }
}
