<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Exceptions\InboxItemNotFoundException;
use RoundlyConsulting\Notifications\Models\InboxItem;

/**
 * Inbox queries scoped to one recipient — the inbox's security boundary.
 *
 * @internal
 */
final class InboxQueries
{
    /**
     * @return Builder<InboxItem>
     */
    public static function for(Model $recipient): Builder
    {
        return NotificationModels::inboxItem()::query()
            ->where('recipient_type', $recipient->getMorphClass())
            ->where('recipient_id', $recipient->getKey());
    }

    /**
     * The recipient's item, or InboxItemNotFoundException — the same answer for another
     * recipient's item, so the inbox is no existence oracle.
     */
    public static function find(Model $recipient, InboxItem|int|string $item, bool $lock = false): InboxItem
    {
        $id = $item instanceof InboxItem ? ModelRefs::key($item) : $item;

        if (is_string($id) && ! self::looksLikeAKey($id)) {
            throw InboxItemNotFoundException::notFound();
        }

        $query = self::for($recipient)->whereKey($id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw InboxItemNotFoundException::notFound();
    }

    private static function looksLikeAKey(string $id): bool
    {
        return preg_match('/^[0-9A-Za-z-]{1,64}$/', $id) === 1
            && (NotificationsConfig::primaryKeyType()->value !== 'bigint' || ctype_digit($id));
    }
}
