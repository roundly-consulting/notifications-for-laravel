<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The IANA timezone quiet hours and digest slots are computed in for one recipient.
 */
interface ResolvesTimezone
{
    public function timezone(Model $recipient): string;
}
