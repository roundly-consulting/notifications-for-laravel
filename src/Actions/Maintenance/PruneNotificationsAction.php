<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Maintenance;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Notifications\DataTransferObjects\PruneReport;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Enums\ReminderStatus;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;

/**
 * Force-deletes old rows in chunks: read and archived inbox items, terminal deliveries, finished
 * digest runs with no live entry, terminal reminders with no live escalation, expired receipts.
 */
final readonly class PruneNotificationsAction
{
    private const int CHUNK = 500;

    public function execute(): PruneReport
    {
        $now = Clock::now();

        $inbox = $this->prune(
            NotificationModels::inboxItem()::query()->withTrashed()
                ->where(static fn (Builder $query): Builder => $query
                    ->where(static fn (Builder $q): Builder => $q->whereNotNull('read_at')->whereNotNull('archived_at'))
                    ->orWhereNotNull('deleted_at'))
                ->where('last_event_at', '<', $now->sub(NotificationsConfig::pruneInboxAfter())->format(Clock::FORMAT)),
        );

        $deliveries = $this->prune(
            NotificationModels::delivery()::query()->withTrashed()
                ->whereIn('status', [DeliveryStatus::Sent->value, DeliveryStatus::Cancelled->value, DeliveryStatus::Failed->value, DeliveryStatus::Undeliverable->value])
                ->where('updated_at', '<', $now->sub(NotificationsConfig::pruneDeliveriesAfter())->format(Clock::FORMAT)),
        );

        $runTable = (new (NotificationModels::digestRun()))->getTable();
        $deliveryTable = (new (NotificationModels::delivery()))->getTable();

        $runs = $this->prune(
            NotificationModels::digestRun()::query()->withTrashed()
                ->whereIn('status', [DigestRunStatus::Sent->value, DigestRunStatus::Empty->value, DigestRunStatus::Failed->value])
                ->where('updated_at', '<', $now->sub(NotificationsConfig::pruneDigestRunsAfter())->format(Clock::FORMAT))
                ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->from($deliveryTable)
                    ->whereColumn($deliveryTable.'.digest_run_id', $runTable.'.id')
                    ->whereIn($deliveryTable.'.status', [DeliveryStatus::Digest->value, DeliveryStatus::Pending->value, DeliveryStatus::Held->value, DeliveryStatus::Sending->value])),
        );

        $reminderTable = (new (NotificationModels::reminder()))->getTable();

        $reminders = $this->prune(
            NotificationModels::reminder()::query()->withTrashed()
                ->whereIn('status', [ReminderStatus::Sent->value, ReminderStatus::Cancelled->value, ReminderStatus::Skipped->value])
                ->where('updated_at', '<', $now->sub(NotificationsConfig::pruneRemindersAfter())->format(Clock::FORMAT))
                ->whereNotExists(static fn (QueryBuilder $query): QueryBuilder => $query->from($reminderTable, 'children')
                    ->whereColumn('children.parent_id', $reminderTable.'.id')
                    ->whereIn('children.status', [ReminderStatus::Pending->value, ReminderStatus::Firing->value])),
        );

        $receipts = $this->prune(
            NotificationModels::receipt()::query()
                ->where('created_at', '<', $now->sub(NotificationsConfig::idempotencyTtl())->format(Clock::FORMAT)),
        );

        return new PruneReport($inbox, $deliveries, $runs, $reminders, $receipts);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function prune(Builder $query): int
    {
        $deleted = 0;

        do {
            $ids = (clone $query)->limit(self::CHUNK)->pluck($query->getModel()->getKeyName())->all();

            if ($ids === []) {
                break;
            }

            $deleted += $query->getModel()->newQueryWithoutScopes()->whereKey($ids)->toBase()->delete();
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }
}
