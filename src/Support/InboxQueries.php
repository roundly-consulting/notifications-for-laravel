<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Notifications\Exceptions\InboxItemNotFoundException;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

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
    public static function find(Model $recipient, InboxItem|int|string $item): InboxItem
    {
        $id = $item instanceof InboxItem ? ModelRefs::key($item) : $item;

        if (is_string($id) && ! self::looksLikeAKey($id)) {
            throw InboxItemNotFoundException::notFound();
        }

        return self::for($recipient)->whereKey($id)->first() ?? throw InboxItemNotFoundException::notFound();
    }

    /**
     * A string id of the configured key type — anything else is "not found" before it reaches
     * SQL (PostgreSQL answers a malformed uuid, or an integer beyond bigint, with an error, not an
     * empty result).
     */
    private static function looksLikeAKey(string $id): bool
    {
        return match (NotificationsConfig::primaryKeyType()) {
            KeyType::BigInt => KeyTypes::isBigInt($id),
            KeyType::Uuid => Str::isUuid($id),
            KeyType::Ulid => Str::isUlid($id),
        };
    }
}
