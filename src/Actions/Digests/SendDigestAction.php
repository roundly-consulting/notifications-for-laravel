<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Digests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestRunStatus;
use RoundlyConsulting\Notifications\Events\DigestSent;
use RoundlyConsulting\Notifications\Mail\DigestMailNotification;
use RoundlyConsulting\Notifications\Models\DigestRun;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;
use RoundlyConsulting\Notifications\Support\Access;
use RoundlyConsulting\Notifications\Support\Clock;
use RoundlyConsulting\Notifications\Support\DigestEntry;
use RoundlyConsulting\Notifications\Support\DigestRenderer;
use RoundlyConsulting\Notifications\Support\DigestRuns;
use RoundlyConsulting\Notifications\Support\Locales;
use RoundlyConsulting\Notifications\Support\MailAddresses;
use RoundlyConsulting\Notifications\Support\ModelRefs;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\NotificationsConfig;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\Subjects;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use Throwable;

/**
 * Sends one claimed digest run. Every entry is re-checked (access, mutes) and dropped when it no
 * longer passes; a run with nothing left sends no mail. The address comes from
 * `notifications.mail.address_resolver` when one is configured, exactly as for typed mail. A failed send leaves the run pending for
 * the next sweep until `notifications.mail.max_attempts`.
 *
 * @internal
 */
final readonly class SendDigestAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    public function execute(int|string $runId): void
    {
        $run = NotificationModels::digestRun()::query()->find($runId);

        if ($run === null || $run->status !== DigestRunStatus::Sending) {
            return;
        }

        $recipient = ModelRefs::find($run->recipient_type, $run->recipient_id);

        if ($recipient === null) {
            DigestRuns::finish($run, DigestRunStatus::Empty, CancelReason::RecipientGone);

            return;
        }

        $entries = [];

        foreach ($run->deliveries()->where('status', DeliveryStatus::Digest->value)->orderBy('first_event_at')->get() as $delivery) {
            $entry = $this->entry($delivery, $recipient);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        if ($entries === []) {
            DigestRuns::finish($run, DigestRunStatus::Empty);

            return;
        }

        try {
            $message = Locales::for($recipient, static fn () => DigestRenderer::render($recipient, $run->frequency, $entries));
            $address = MailAddresses::resolve($recipient, null);

            if ($address === false) {
                DigestRuns::finish($run, DigestRunStatus::Failed, CancelReason::NoAddress);

                return;
            }

            $notification = new DigestMailNotification(ModelRefs::key($run), count($entries), $message);

            if ($address === true) {
                Notification::sendNow($recipient, $notification);
            } else {
                $locale = Locales::preferred($recipient);

                if ($locale !== null) {
                    $notification->locale($locale);
                }

                (new AnonymousNotifiable)->route('mail', $address)->notifyNow($notification);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->failed($run);

            return;
        }

        $ids = array_map(static fn (DigestEntry $entry): int|string => ModelRefs::key($entry->delivery), $entries);

        NotificationModels::delivery()::query()->whereKey($ids)->update([
            'status' => DeliveryStatus::Sent->value,
            'sent_at' => Clock::now()->format(Clock::FORMAT),
            'open_key' => null,
        ]);

        NotificationModels::digestRun()::query()->whereKey($run->getKey())->update(['items_count' => count($entries)]);
        DigestRuns::finish($run, DigestRunStatus::Sent);

        Event::dispatch(new DigestSent($run->refresh(), count($entries)));
    }

    /**
     * The entry, or null when it no longer passes (it is cancelled).
     */
    private function entry(NotificationDelivery $delivery, Model $recipient): ?DigestEntry
    {
        $type = $this->types->get($delivery->type);
        $reason = null;
        $subject = null;

        if ($type === null) {
            $reason = CancelReason::UnknownType;
        } else {
            $subjectGiven = $delivery->subject_type !== null;
            $subject = $subjectGiven ? Subjects::find($type, $delivery->subject_type, $delivery->subject_id) : null;

            if ($subjectGiven && $subject === null) {
                $reason = CancelReason::SubjectGone;
            } elseif (! Access::allows($type, $recipient, $subject, $subjectGiven)) {
                $reason = CancelReason::NoAccess;
            } elseif (! Preferences::enabled($type, Channel::Mail, $recipient)) {
                $reason = CancelReason::Muted;
            }
        }

        if ($reason !== null) {
            $delivery->forceFill(['status' => DeliveryStatus::Cancelled, 'cancel_reason' => $reason, 'open_key' => null])->save();

            return null;
        }

        return new DigestEntry($delivery, $type, $subject);
    }

    private function failed(DigestRun $run): void
    {
        $fresh = $run->fresh() ?? $run;

        if ($fresh->attempts >= NotificationsConfig::maxAttempts()) {
            DigestRuns::finish($fresh, DigestRunStatus::Failed, CancelReason::Transport);

            return;
        }

        NotificationModels::digestRun()::query()->whereKey($run->getKey())
            ->where('status', DigestRunStatus::Sending->value)
            ->update(['status' => DigestRunStatus::Pending->value]);
    }
}
