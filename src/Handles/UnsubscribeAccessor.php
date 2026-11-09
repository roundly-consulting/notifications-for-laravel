<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Handles;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Notifications\Actions\Preferences\RedeemUnsubscribeAction;
use RoundlyConsulting\Notifications\DataTransferObjects\UnsubscribeClaim;
use RoundlyConsulting\Notifications\DataTransferObjects\UnsubscribeResult;
use RoundlyConsulting\Notifications\Enums\NotificationOperation;
use RoundlyConsulting\Notifications\Enums\UnsubscribeOutcome;
use RoundlyConsulting\Notifications\Exceptions\InvalidUnsubscribeTokenException;
use RoundlyConsulting\Notifications\NotificationsManager;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Support\UnsubscribeLinks;
use RoundlyConsulting\Notifications\Support\UnsubscribeTokens;

/**
 * One-click unsubscribe links (`notifications.mail.list_unsubscribe`):
 * `Notifications::unsubscribe()->url($user, 'comment.posted')` for your own mail or page, and
 * `->redeem($token)` behind your own endpoint.
 */
final readonly class UnsubscribeAccessor
{
    public function __construct(
        private NotificationsManager $manager,
    ) {}

    /**
     * The signed link that switches the type's mail off for the recipient; null when the feature
     * is off or the type is mandatory (or sends no mail).
     */
    public function url(Model $recipient, string $type): ?string
    {
        return UnsubscribeLinks::url($recipient, app(TypeRegistry::class)->find($type));
    }

    /**
     * What a link names, without changing anything (a confirmation page).
     *
     * @throws InvalidUnsubscribeTokenException
     */
    public function claim(string $token): UnsubscribeClaim
    {
        return UnsubscribeTokens::read($token);
    }

    /**
     * Switch the mail off for the recipient and type the link names.
     *
     * @throws InvalidUnsubscribeTokenException
     */
    public function redeem(string $token): UnsubscribeResult
    {
        return $this->manager->perform(
            NotificationOperation::Unsubscribe,
            RedeemUnsubscribeAction::class,
            static fn (RedeemUnsubscribeAction $action): UnsubscribeResult => $action->execute($token),
            ['token' => $token],
        ) ?? self::recordOnly(UnsubscribeTokens::read($token));
    }

    /**
     * Under the fake (nothing ran): the link's claim, answered as unsubscribed.
     */
    private static function recordOnly(UnsubscribeClaim $claim): UnsubscribeResult
    {
        return new UnsubscribeResult(UnsubscribeOutcome::Unsubscribed, $claim->type, $claim->recipient);
    }
}
