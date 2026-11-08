<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Enums\CancelReason;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Models\NotificationDelivery;

/**
 * Whether a parked delivery still belongs in the recipient's digest: its type is registered, its
 * subject is there and visible to the recipient, and the type's mail is not muted. One rule set for
 * the digest (which cancels what fails) and its preview (which leaves it out).
 *
 * @internal
 */
final class DigestEntries
{
    public static function resolve(NotificationDelivery $delivery, Model $recipient, TypeRegistry $types): DigestEntry|CancelReason
    {
        $type = $types->get($delivery->type);

        if ($type === null) {
            return CancelReason::UnknownType;
        }

        $subjectGiven = $delivery->subject_type !== null;
        $subject = $subjectGiven ? Subjects::find($type, $delivery->subject_type, $delivery->subject_id) : null;

        if ($subjectGiven && $subject === null) {
            return CancelReason::SubjectGone;
        }

        if (! Access::allows($type, $recipient, $subject, $subjectGiven)) {
            return CancelReason::NoAccess;
        }

        if (! Preferences::enabled($type, Channel::Mail, $recipient)) {
            return CancelReason::Muted;
        }

        return new DigestEntry($delivery, $type, $subject);
    }
}
