<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Models\DigestRun;

/**
 * The digest run ledger: one row per recipient, frequency and period.
 *
 * @internal
 */
final class DigestRuns
{
    public static function firstOrCreate(string $recipientType, int|string $recipientId, DigestFrequency $frequency, string $period): DigestRun
    {
        $class = NotificationModels::digestRun();

        $find = static fn () => $class::query()->withTrashed()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('frequency', $frequency->value)
            ->where('period', $period)
            ->first();

        $run = $find();

        if ($run !== null) {
            return $run;
        }

        $run = new $class;
        $run->forceFill([
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'frequency' => $frequency,
            'period' => $period,
            'status' => DigestRunStatus::Pending,
            'items_count' => 0,
            'attempts' => 0,
        ]);

        try {
            $run->getConnection()->transaction(static fn (): bool => $run->save());
        } catch (UniqueConstraintViolationException $exception) {
            return $find() ?? throw $exception;
        }

        return $run;
    }

    /**
     * The sweep's claim: `pending → sending`, queued. `claimed_at` stays empty until the job
     * starts ({@see self::begin()}), so time spent waiting in the queue never counts against
     * `digest.lease` — the lease is for a worker that died while sending.
     */
    public static function claim(DigestRun $run): bool
    {
        return NotificationModels::digestRun()::query()
            ->whereKey($run->getKey())
            ->where('status', DigestRunStatus::Pending->value)
            ->incrementEach(['attempts' => 1], ['status' => DigestRunStatus::Sending->value, 'claimed_at' => null]) > 0;
    }

    /**
     * The job's start: stamps `claimed_at` on a queued run. False when another job (a re-dispatch
     * of the same run) already started it.
     */
    public static function begin(DigestRun $run): bool
    {
        return NotificationModels::digestRun()::query()
            ->whereKey($run->getKey())
            ->where('status', DigestRunStatus::Sending->value)
            ->whereNull('claimed_at')
            ->update(['claimed_at' => Clock::now()->format(Clock::FORMAT)]) > 0;
    }

    /**
     * End a run and, for a failure, its attached entries in the same statement — so they are
     * neither re-grouped forever nor silently lost.
     */
    public static function finish(DigestRun $run, DigestRunStatus $status, ?CancelReason $entriesReason = null): void
    {
        $values = ['status' => $status->value];

        if ($status === DigestRunStatus::Sent) {
            $values['sent_at'] = Clock::now()->format(Clock::FORMAT);
        }

        NotificationModels::digestRun()::query()->whereKey($run->getKey())->update($values);

        if ($entriesReason !== null) {
            NotificationModels::delivery()::query()
                ->where('digest_run_id', $run->getKey())
                ->where('status', DeliveryStatus::Digest->value)
                ->update(['status' => DeliveryStatus::Failed->value, 'cancel_reason' => $entriesReason->value, 'open_key' => null]);
        }
    }
}
