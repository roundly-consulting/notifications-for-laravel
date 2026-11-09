<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\NotificationType;
use Symfony\Component\Mime\Email;

/**
 * List-Unsubscribe (RFC 2369) and one-click List-Unsubscribe-Post (RFC 8058) on typed mail. Only
 * a type whose mail the recipient can switch off gets them — never a mandatory type.
 *
 * @internal
 */
final class UnsubscribeLinks
{
    public const string ROUTE = 'notifications.unsubscribe';

    /**
     * The one-click link of the type's mail for the recipient; null when the feature is off or the
     * type's mail cannot be switched off.
     */
    public static function url(Model $recipient, NotificationType $type): ?string
    {
        if (! NotificationsConfig::listUnsubscribeEnabled() || ! Preferences::switchable($type, Channel::Mail)) {
            return null;
        }

        return URL::route(self::ROUTE, ['token' => UnsubscribeTokens::issue($recipient, $type->key())]);
    }

    /**
     * Adds the headers to a typed mail. RFC 8058 one-click needs an https link: over plain http only
     * List-Unsubscribe is added.
     */
    public static function apply(MailMessage $message, Model $recipient, NotificationType $type): MailMessage
    {
        $url = self::url($recipient, $type);

        if ($url === null) {
            return $message;
        }

        $oneClick = str_starts_with(strtolower($url), 'https://');

        return $message->withSymfonyMessage(static function (Email $email) use ($url, $oneClick): void {
            $headers = $email->getHeaders();
            $headers->remove('List-Unsubscribe');
            $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');

            if ($oneClick) {
                $headers->remove('List-Unsubscribe-Post');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
        });
    }
}
