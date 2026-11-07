<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Inbox\ArchiveInboxItemAction;
use RoundlyConsulting\Notifications\Actions\Inbox\DeleteInboxItemAction;
use RoundlyConsulting\Notifications\Actions\Inbox\MarkAllInboxItemsReadAction;
use RoundlyConsulting\Notifications\Actions\Inbox\MarkInboxItemReadAction;
use RoundlyConsulting\Notifications\Actions\Inbox\MarkInboxItemUnreadAction;
use RoundlyConsulting\Notifications\Actions\Inbox\UnarchiveInboxItemAction;
use RoundlyConsulting\Notifications\DataTransferObjects\ContextCount;
use RoundlyConsulting\Notifications\DataTransferObjects\InboxCounts;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Models\InboxItem;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Support\Access;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\Contexts;
use RoundlyConsulting\Notifications\Support\InboxQueries;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * One recipient's inbox: immutable filters, paginated reads and counts, and mutations that only
 * ever touch this recipient's items (another recipient's item is "not found").
 *
 * ```php
 * $inbox->in($company)->unread()->paginate();
 * $inbox->markAllRead(in: $company);
 * ```
 */
final readonly class InboxHandle
{
    /**
     * @param  'active'|'unread'|'read'|'archived'|'all'  $status
     */
    public function __construct(
        private NotificationsManager $manager,
        private Model $recipient,
        private string $status = 'active',
        private ?string $context = null,
        private bool $withoutContext = false,
        private ?string $category = null,
        private ?string $type = null,
        private ?CarbonInterface $since = null,
    ) {}

    public function recipient(): Model
    {
        return $this->recipient;
    }

    // Filters ----------------------------------------------------------------------------

    /**
     * Unread, not archived items.
     */
    public function unread(): self
    {
        return $this->with(status: 'unread');
    }

    /**
     * Read, not archived items.
     */
    public function read(): self
    {
        return $this->with(status: 'read');
    }

    public function archived(): self
    {
        return $this->with(status: 'archived');
    }

    /**
     * Archived and active items together.
     */
    public function withArchived(): self
    {
        return $this->with(status: 'all');
    }

    /**
     * One context: a model or a context key string (`company:3`).
     */
    public function in(Model|string $context): self
    {
        return $this->with(context: Contexts::keyOf($context), withoutContext: false);
    }

    /**
     * Only items without a context.
     */
    public function withoutContext(): self
    {
        return $this->with(context: null, withoutContext: true);
    }

    /**
     * Every context (the default).
     */
    public function allContexts(): self
    {
        return $this->with(context: null, withoutContext: false);
    }

    public function category(string $category): self
    {
        return $this->with(category: $category);
    }

    public function type(string $type): self
    {
        return $this->with(type: $type);
    }

    /**
     * Items with activity since an instant.
     */
    public function since(CarbonInterface $since): self
    {
        return $this->with(since: $since);
    }

    /**
     * Newest activity first (the default order).
     */
    public function latest(): self
    {
        return $this;
    }

    // Reads ------------------------------------------------------------------------------

    /**
     * @return LengthAwarePaginator<int, InboxItem>
     */
    public function paginate(?int $perPage = null): LengthAwarePaginator
    {
        $paginator = $this->query()->paginate($this->perPage($perPage));

        if (NotificationsConfig::recheckOnRead()) {
            $paginator->setCollection($paginator->getCollection()->filter($this->allowed(...))->values());
        }

        return $paginator;
    }

    /**
     * @return CursorPaginator<int, InboxItem>
     */
    public function cursorPaginate(?int $perPage = null): CursorPaginator
    {
        $paginator = $this->query()->cursorPaginate($this->perPage($perPage));

        if (NotificationsConfig::recheckOnRead()) {
            $paginator->setCollection($paginator->getCollection()->filter($this->allowed(...))->values());
        }

        return $paginator;
    }

    /**
     * @return Collection<int, InboxItem>
     */
    public function get(?int $limit = null): Collection
    {
        $items = $this->query()->limit($limit ?? NotificationsConfig::maxPerPage())->get();

        return NotificationsConfig::recheckOnRead() ? $items->filter($this->allowed(...))->values() : $items;
    }

    public function first(): ?InboxItem
    {
        return $this->get(1)->first();
    }

    /**
     * One of this recipient's items (another recipient's id is "not found").
     */
    public function find(InboxItem|int|string $item): InboxItem
    {
        return InboxQueries::find($this->recipient, $item)->loadMissing(['actor', 'subject']);
    }

    public function unreadCount(): int
    {
        return $this->filtered(InboxQueries::for($this->recipient))->whereNull('read_at')->whereNull('archived_at')->count();
    }

    public function counts(): InboxCounts
    {
        $base = InboxQueries::for($this->recipient)->whereNull('archived_at');

        $unread = (clone $base)->whereNull('read_at')->count();
        $total = (clone $base)->count();

        $groups = (clone $base)->whereNull('read_at')->whereNotNull('context_key')
            ->selectRaw('context_key, count(*) as unread_count')
            ->groupBy('context_key')
            ->toBase()
            ->get();

        $contexts = [];

        foreach ($groups as $group) {
            $key = property_exists($group, 'context_key') && is_string($group->context_key) ? $group->context_key : null;

            if ($key === null) {
                continue;
            }

            $count = property_exists($group, 'unread_count') && is_numeric($group->unread_count) ? (int) $group->unread_count : 0;
            $contexts[] = new ContextCount($key, $this->label($key), $count);
        }

        return new InboxCounts($unread, $total, $contexts);
    }

    // Mutations --------------------------------------------------------------------------

    public function markRead(InboxItem|int|string $item): self
    {
        $this->manager->perform(
            NotificationOperation::MarkRead,
            MarkInboxItemReadAction::class,
            fn (MarkInboxItemReadAction $action): InboxItem => $action->execute($this->recipient, $item),
            ['recipient' => $this->recipient, 'item' => $item],
        );

        return $this;
    }

    public function markUnread(InboxItem|int|string $item): self
    {
        $this->manager->perform(
            NotificationOperation::MarkUnread,
            MarkInboxItemUnreadAction::class,
            fn (MarkInboxItemUnreadAction $action): InboxItem => $action->execute($this->recipient, $item),
            ['recipient' => $this->recipient, 'item' => $item],
        );

        return $this;
    }

    /**
     * Marks every unread item read (optionally in one context or category); returns how many.
     */
    public function markAllRead(Model|string|null $in = null, ?string $category = null): int
    {
        return (int) $this->manager->perform(
            NotificationOperation::MarkAllRead,
            MarkAllInboxItemsReadAction::class,
            fn (MarkAllInboxItemsReadAction $action): int => $action->execute($this->recipient, $in, $category),
            ['recipient' => $this->recipient, 'in' => $in, 'category' => $category],
        );
    }

    public function archive(InboxItem|int|string $item): self
    {
        $this->manager->perform(
            NotificationOperation::Archive,
            ArchiveInboxItemAction::class,
            fn (ArchiveInboxItemAction $action): InboxItem => $action->execute($this->recipient, $item),
            ['recipient' => $this->recipient, 'item' => $item],
        );

        return $this;
    }

    public function unarchive(InboxItem|int|string $item): self
    {
        $this->manager->perform(
            NotificationOperation::Unarchive,
            UnarchiveInboxItemAction::class,
            fn (UnarchiveInboxItemAction $action): InboxItem => $action->execute($this->recipient, $item),
            ['recipient' => $this->recipient, 'item' => $item],
        );

        return $this;
    }

    public function delete(InboxItem|int|string $item): self
    {
        $this->manager->perform(
            NotificationOperation::Delete,
            DeleteInboxItemAction::class,
            function (DeleteInboxItemAction $action) use ($item): null {
                $action->execute($this->recipient, $item);

                return null;
            },
            ['recipient' => $this->recipient, 'item' => $item],
        );

        return $this;
    }

    // Internals --------------------------------------------------------------------------

    /**
     * @return Builder<InboxItem>
     */
    private function query(): Builder
    {
        return $this->filtered(InboxQueries::for($this->recipient))
            ->with(['actor', 'subject'])
            ->orderByDesc('last_event_at')
            ->orderByDesc('id');
    }

    /**
     * @param  Builder<InboxItem>  $query
     * @return Builder<InboxItem>
     */
    private function filtered(Builder $query): Builder
    {
        match ($this->status) {
            'unread' => $query->whereNull('archived_at')->whereNull('read_at'),
            'read' => $query->whereNull('archived_at')->whereNotNull('read_at'),
            'archived' => $query->whereNotNull('archived_at'),
            'all' => $query,
            default => $query->whereNull('archived_at'),
        };

        if ($this->context !== null) {
            $query->where('context_key', $this->context);
        } elseif ($this->withoutContext) {
            $query->whereNull('context_key');
        }

        if ($this->category !== null) {
            $query->where('category', $this->category);
        }

        if ($this->type !== null) {
            $query->where('type', $this->type);
        }

        if ($this->since !== null) {
            $query->where('last_event_at', '>=', Clock::format($this->since));
        }

        return $query;
    }

    private function perPage(?int $perPage): int
    {
        return max(1, min($perPage ?? NotificationsConfig::perPage(), NotificationsConfig::maxPerPage()));
    }

    /**
     * `notifications.inbox.recheck_on_read`: hide an item whose subject the reader can no longer
     * access (counts stay raw).
     */
    private function allowed(InboxItem $item): bool
    {
        $type = app(TypeRegistry::class)->get($item->type);

        if ($type === null || $item->subject_type === null) {
            return true;
        }

        return Access::allows($type, $this->recipient, Subjects::find($type, $item->subject_type, $item->subject_id), true);
    }

    private function label(string $key): string
    {
        $label = InboxQueries::for($this->recipient)
            ->where('context_key', $key)
            ->orderByDesc('last_event_at')
            ->value('context_label');

        return is_string($label) ? $label : $key;
    }

    /**
     * @param  'active'|'unread'|'read'|'archived'|'all'|null  $status
     */
    private function with(
        ?string $status = null,
        string|false|null $context = false,
        ?bool $withoutContext = null,
        ?string $category = null,
        ?string $type = null,
        ?CarbonInterface $since = null,
    ): self {
        return new self(
            manager: $this->manager,
            recipient: $this->recipient,
            status: $status ?? $this->status,
            context: $context === false ? $this->context : $context,
            withoutContext: $withoutContext ?? $this->withoutContext,
            category: $category ?? $this->category,
            type: $type ?? $this->type,
            since: $since ?? $this->since,
        );
    }
}
