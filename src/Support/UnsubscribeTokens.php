<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use RoundlyConsulting\Notifications\DataTransferObjects\UnsubscribeClaim;
use RoundlyConsulting\Notifications\Exceptions\InvalidUnsubscribeTokenException;

/**
 * The token of an unsubscribe link: the recipient, the type and an optional expiry, encrypted
 * and signed (MAC) with the app key through Laravel's encrypter, so it can be neither read nor
 * forged, and `app.previous_keys` keep old links working after a key rotation. Base64url, so it
 * fits a path segment.
 *
 * @internal
 */
final class UnsubscribeTokens
{
    private const int VERSION = 1;

    public static function issue(Model $recipient, string $type): string
    {
        $ttl = NotificationsConfig::listUnsubscribeTtl();

        $payload = json_encode([
            'v' => self::VERSION,
            'r' => $recipient->getMorphClass(),
            'i' => ModelRefs::key($recipient),
            't' => $type,
            'e' => $ttl === null ? null : Clock::now()->add($ttl)->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(Crypt::encryptString($payload), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidUnsubscribeTokenException tampered, foreign or expired
     */
    public static function read(string $token): UnsubscribeClaim
    {
        if ($token === '' || strlen($token) > 2048 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw InvalidUnsubscribeTokenException::invalid();
        }

        $encrypted = strtr($token, '-_', '+/');
        $encrypted .= str_repeat('=', (4 - strlen($encrypted) % 4) % 4);

        try {
            $payload = json_decode(Crypt::decryptString($encrypted), true);
        } catch (DecryptException) {
            throw InvalidUnsubscribeTokenException::invalid();
        }

        $id = is_array($payload) ? ($payload['i'] ?? null) : null;
        $expires = is_array($payload) ? ($payload['e'] ?? null) : null;

        if (! is_array($payload) || ($payload['v'] ?? null) !== self::VERSION
            || ! is_string($payload['r'] ?? null) || ! is_string($payload['t'] ?? null)
            || ! (is_int($id) || is_string($id)) || ! ($expires === null || is_int($expires))) {
            throw InvalidUnsubscribeTokenException::invalid();
        }

        if ($expires !== null && Clock::now()->getTimestamp() > $expires) {
            throw InvalidUnsubscribeTokenException::invalid();
        }

        return new UnsubscribeClaim(ModelRefs::find($payload['r'], $id), $payload['t']);
    }
}
