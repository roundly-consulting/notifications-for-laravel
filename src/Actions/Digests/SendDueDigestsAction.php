<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Digests;

use RoundlyConsulting\Notifications\Contracts\ResolvesTimezone;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Jobs\SendDigestJob;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\DigestRuns;
use RoundlyConsulting\Notifications\Support\DigestSchedule;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\Queues;

/**
 * Starts every due digest: per recipient, frequency and period, one run row (unique) claimed by
 * compare-and-set, its items attached, a job dispatched. A second sweep or a retried job sends
 * nothing. Items arriving after their period's run was claimed roll into the next period.
 */
final readonly class SendDueDigestsAction
{
    public function execute(): int
    {
        $now = Clock::now();
        $started = 0;

        $groups = NotificationModels::delivery()::query()
            ->where('status', DeliveryStatus::Digest->value)
            ->whereNull('digest_run_id')
            ->where('send_after', '<=', $now->format(Clock::FORMAT))
            ->select(['recipient_type', 'recipient_id', 'digest_frequency', 'digest_period'])
            ->distinct()
            ->toBase()
            ->get();

        foreach ($groups as $group) {
            $type = property_exists($group, 'recipient_type') && is_string($group->recipient_type) ? $group->recipient_type : null;
            $id = property_exists($group, 'recipient_id') && (is_int($group->recipient_id) || is_string($group->recipient_id)) ? $group->recipient_id : null;
            $frequency = property_exists($group, 'digest_frequency') && is_string($group->digest_frequency) ? DigestFrequency::tryFrom($group->digest_frequency) : null;
            $period = property_exists($group, 'digest_period') && is_string($group->digest_period) ? $group->digest_period : null;

            if ($type === null || $id === null || $frequency === null || $period === null) {
                continue;
            }

            $run = DigestRuns::firstOrCreate($type, $id, $frequency, $period);

            if ($run->status !== DigestRunStatus::Pending) {
                $this->rollOver($type, $id, $frequency, $period);

                continue;
            }

            if ($this->start($run, $type, $id, $frequency, $period)) {
                $started++;
            }
        }

        // Runs whose send failed and went back to pending (their items are attached).
        $retries = NotificationModels::digestRun()::query()
            ->where('status', DigestRunStatus::Pending->value)
            ->where('attempts', '>', 0)
            ->get();

        foreach ($retries as $run) {
            if (DigestRuns::claim($run)) {
                Queues::dispatch(new SendDigestJob(ModelRefs::key($run)));
                $started++;
            }
        }

        return $started;
    }

    private function start(DigestRun $run, string $type, int|string $id, DigestFrequency $frequency, string $period): bool
    {
        return (bool) $run->getConnection()->transaction(static function () use ($run, $type, $id, $frequency, $period): bool {
            if (! DigestRuns::claim($run)) {
                return false;
            }

            NotificationModels::delivery()::query()
                ->where('status', DeliveryStatus::Digest->value)
                ->whereNull('digest_run_id')
                ->where('recipient_type', $type)
                ->where('recipient_id', $id)
                ->where('digest_frequency', $frequency->value)
                ->where('digest_period', $period)
                ->update(['digest_run_id' => $run->getKey()]);

            Queues::dispatch(new SendDigestJob(ModelRefs::key($run)));

            return true;
        });
    }

    /**
     * Unattached items of a period whose run already went out (or is going out) move to the
     * recipient's next slot.
     */
    private function rollOver(string $type, int|string $id, DigestFrequency $frequency, string $period): void
    {
        $recipient = ModelRefs::find($type, $id);
        $now = Clock::now();

        if ($recipient === null) {
            [$due, $next] = [$now, DigestSchedule::flushPeriod($now)];
        } else {
            $digest = Preferences::digest($recipient);
            $timezone = app(ResolvesTimezone::class)->timezone($recipient);

            [$due, $next] = $digest->frequency === DigestFrequency::Off || str_starts_with($period, 'off-')
                ? [$now, DigestSchedule::flushPeriod($now->addMinute())]
                : DigestSchedule::next($now, $timezone, $frequency, $digest->time ?? NotificationsConfig::digestTime(), Preferences::quietHours($recipient));
        }

        if ($next === $period) {
            return;
        }

        NotificationModels::delivery()::query()
            ->where('status', DeliveryStatus::Digest->value)
            ->whereNull('digest_run_id')
            ->where('recipient_type', $type)
            ->where('recipient_id', $id)
            ->where('digest_frequency', $frequency->value)
            ->where('digest_period', $period)
            ->update(['digest_period' => $next, 'send_after' => $due->format(Clock::FORMAT)]);
    }
}
