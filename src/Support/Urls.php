<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Support;

/**
 * Action links are absolute http(s) URLs only: a `javascript:` or relative value is never
 * rendered, in mail or over the wire.
 *
 * @internal
 */
final class Urls
{
    public static function safe(?string $url): ?string
    {
        if ($url === null || strlen($url) > 2048) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return null;
        }

        return $url;
    }
}
