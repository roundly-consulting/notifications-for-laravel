<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications;

use Closure;

/**
 * A notification holding a closure: it cannot be serialized, so it is never held.
 */
class UnserializableNotice extends PlainVendorNotice
{
    public Closure $callback;

    public function __construct()
    {
        parent::__construct('closure');
        $this->callback = fn (): string => 'x';
    }
}
