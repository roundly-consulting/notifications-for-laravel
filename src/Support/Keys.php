<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Crypto\Facades\Crypto;

/**
 * The digests behind every unique "open key": one inbox item and one delivery per recipient,
 * type, subject, context and lane share their parts, so they always pair up.
 *
 * @internal
 */
final class Keys
{
    public const string ORDINARY_LANE = '-';

    public static function lane(int $escalationLevel): string
    {
        return $escalationLevel > 0 ? 'esc:'.$escalationLevel : self::ORDINARY_LANE;
    }

    public static function collapse(Model $recipient, string $type, ?string $subject, ?string $contextKey, string $lane): string
    {
        return self::digest(implode('|', ['inbox', ModelRefs::identity($recipient), $type, $subject ?? '-', $contextKey ?? '-', $lane]));
    }

    public static function dedupe(Model $recipient, string $type, ?string $subject, ?string $contextKey, string $lane): string
    {
        return self::digest(implode('|', ['inbox', ModelRefs::identity($recipient), $type, $subject ?? '-', $contextKey ?? '-', $lane, 'mail']));
    }

    public static function receipt(string $idempotencyKey): string
    {
        return self::digest('receipt|'.$idempotencyKey);
    }

    public static function reminder(string $subject, string $type, string $rule, ?string $offset): string
    {
        return self::digest(implode('|', ['reminder', $subject, $type, $rule, $offset ?? '-']));
    }

    public static function escalation(string $parent, int $level): string
    {
        return self::digest(implode('|', ['escalation', $parent, (string) $level]));
    }

    public static function digest(string $value): string
    {
        return Crypto::digest()->hex($value);
    }
}
