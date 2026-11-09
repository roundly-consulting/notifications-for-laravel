<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Actions\Preferences;

use RoundlyConsulting\Notifications\DataTransferObjects\UnsubscribeResult;
use RoundlyConsulting\Notifications\Enums\Channel;
use RoundlyConsulting\Notifications\Enums\UnsubscribeOutcome;
use RoundlyConsulting\Notifications\Exceptions\InvalidUnsubscribeTokenException;
use RoundlyConsulting\Notifications\Support\Preferences;
use RoundlyConsulting\Notifications\Support\TypeRegistry;
use RoundlyConsulting\Notifications\Support\UnsubscribeTokens;

/**
 * Redeems a List-Unsubscribe link: switches the type's mail off for the recipient it names —
 * the same per-type preference `preferences($user)->mute($type, Channel::Mail)` writes, so the
 * user's settings screen shows it and can switch it back on. Idempotent: a second redeem changes
 * nothing. A type that became mandatory is left on; a gone recipient or type answers Gone.
 */
final readonly class RedeemUnsubscribeAction
{
    public function __construct(
        private TypeRegistry $types,
        private SetChannelPreferenceAction $setChannel,
    ) {}

    /**
     * @throws InvalidUnsubscribeTokenException
     */
    public function execute(string $token): UnsubscribeResult
    {
        $claim = UnsubscribeTokens::read($token);
        $type = $this->types->get($claim->type);

        if ($claim->recipient === null || $type === null) {
            return new UnsubscribeResult(UnsubscribeOutcome::Gone, $claim->type, $claim->recipient);
        }

        if (! in_array(Channel::Mail, $type->channels(), true) || ! Preferences::enabled($type, Channel::Mail, $claim->recipient)) {
            return new UnsubscribeResult(UnsubscribeOutcome::AlreadyUnsubscribed, $type->key(), $claim->recipient);
        }

        if (! Preferences::switchable($type, Channel::Mail)) {
            return new UnsubscribeResult(UnsubscribeOutcome::Required, $type->key(), $claim->recipient);
        }

        $this->setChannel->execute($claim->recipient, $type->key(), [Channel::Mail], false);

        return new UnsubscribeResult(UnsubscribeOutcome::Unsubscribed, $type->key(), $claim->recipient);
    }
}
