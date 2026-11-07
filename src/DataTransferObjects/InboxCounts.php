<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

/**
 * An inbox at a glance: unread and total (not archived) items, and unread per context.
 */
final readonly class InboxCounts
{
    /**
     * @param  list<ContextCount>  $contexts
     */
    public function __construct(
        public int $unread,
        public int $total,
        public array $contexts,
    ) {}
}
