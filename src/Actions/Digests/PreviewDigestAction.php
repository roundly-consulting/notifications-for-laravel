<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Digests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use RoundlyConsulting\Notifications\Enums\DeliveryStatus;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use RoundlyConsulting\Notifications\Support\DigestEntries;
use RoundlyConsulting\Notifications\Support\DigestEntry;
use RoundlyConsulting\Notifications\Support\DigestRenderer;
use RoundlyConsulting\Notifications\Support\Locales;
use RoundlyConsulting\Notifications\Support\NotificationModels;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\TypeRegistry;

/**
 * The digest a user would get from their parked items right now — rendered, never sent. Entries
 * the digest would drop (no access, muted, subject gone) are left out; nothing is written.
 */
final readonly class PreviewDigestAction
{
    public function __construct(
        private TypeRegistry $types,
    ) {}

    public function execute(Model $user): ?MailMessage
    {
        $deliveries = NotificationModels::delivery()::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->where('status', DeliveryStatus::Digest->value)
            ->orderBy('first_event_at')
            ->get();

        $entries = [];

        foreach ($deliveries as $delivery) {
            // The digest's own checks, without its writes: what it would drop is not previewed.
            $entry = DigestEntries::resolve($delivery, $user, $this->types);

            if ($entry instanceof DigestEntry) {
                $entries[] = $entry;
            }
        }

        if ($entries === []) {
            return null;
        }

        $frequency = Preferences::digest($user)->frequency;
        $frequency = $frequency === DigestFrequency::Off ? DigestFrequency::Daily : $frequency;

        return Locales::for($user, static fn (): MailMessage => DigestRenderer::render($user, $frequency, $entries));
    }
}
