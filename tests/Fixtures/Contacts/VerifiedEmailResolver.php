<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\Contacts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Contacts\Enums\ContactType;
use RoundlyConsulting\Notifications\Contracts\ResolvesMailAddress;
use RoundlyConsulting\Notifications\NotificationPayload;

/**
 * The "verified only" recipe from the docs.
 */
final class VerifiedEmailResolver implements ResolvesMailAddress
{
    public function address(Model $recipient, NotificationPayload $payload): ?string
    {
        if (! $recipient instanceof ContactUser) {
            return null;
        }

        $contact = $recipient->primaryContact(ContactType::Email);

        return $contact !== null && $contact->verified_at !== null ? $contact->value : null;
    }
}
