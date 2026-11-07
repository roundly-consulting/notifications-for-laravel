<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\DataTransferObjects;

use RoundlyConsulting\Notifications\Enums\Channel;

/**
 * One channel of one type for one user: effective value, the type's default, and whether the user
 * may change it (mandatory types and, by default, the inbox are locked on).
 */
final readonly class ChannelPreference
{
    public function __construct(
        public Channel $channel,
        public bool $enabled,
        public bool $default,
        public bool $locked,
    ) {}
}
