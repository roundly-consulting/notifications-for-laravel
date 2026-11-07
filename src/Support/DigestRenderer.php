<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use RoundlyConsulting\Notifications\Enums\DigestFrequency;
use Throwable;

/**
 * Renders a digest from its entries, grouped by context then category, in the current (the
 * recipient's) locale. A sensitive entry shows the minimal line.
 *
 * @internal
 */
final class DigestRenderer
{
    /**
     * @param  list<DigestEntry>  $entries
     */
    public static function render(Model $recipient, DigestFrequency $frequency, array $entries): MailMessage
    {
        $max = NotificationsConfig::digestMaxItems();
        $groups = [];

        foreach (array_slice($entries, 0, $max) as $entry) {
            [$delivery, $type, $subject] = [$entry->delivery, $entry->type, $entry->subject];
            $payload = DeliveryPayloads::payload($delivery, $recipient, $subject);

            try {
                $line = MailRenderer::minimal($type, $subject)
                    ? Messages::get('mail.minimal_line', ['app' => Messages::appName()])
                    : $type->digestLine($payload);
                $url = Urls::safe($type->url($payload));
            } catch (Throwable $exception) {
                report($exception);
                $line = $type->label();
                $url = null;
            }

            if ($payload->count > 1) {
                $line .= ' ('.$payload->count.')';
            }

            $context = $delivery->context_label ?? '';
            $category = TypeDefinitions::category($type->category())->label;

            $groups[$context][$category][] = ['line' => $line, 'url' => $url];
        }

        $count = count($entries);
        $subject = Messages::choice('digest.subject_'.$frequency->value, $count, ['count' => $count, 'app' => Messages::appName()]);

        return (new MailMessage)
            ->subject($subject)
            ->markdown('notifications::mail.digest', [
                'greeting' => MailRenderer::greeting($recipient),
                'intro' => Messages::choice('digest.intro', $count, ['count' => $count]),
                'groups' => $groups,
                'more' => $count > $max ? Messages::choice('digest.and_more', $count - $max, ['count' => $count - $max]) : null,
                'noContext' => Messages::get('digest.no_context'),
                'footer' => MailRenderer::preferencesUrl() === null ? null : Messages::get('mail.preferences_footer', ['url' => (string) MailRenderer::preferencesUrl()]),
            ]);
    }
}
