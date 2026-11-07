<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A subject that names who owns it, for Recipients::owner().
 */
interface HasNotificationOwner
{
    /**
     * @return Model|iterable<Model|null>|null
     */
    public function notificationOwner(): Model|iterable|null;
}
