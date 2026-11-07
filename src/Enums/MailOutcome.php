<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

/**
 * What one send did to one recipient's mail.
 */
enum MailOutcome: string
{
    case Pending = 'pending';
    case Held = 'held';
    case Digest = 'digest';
    case Folded = 'folded';
    case Deduplicated = 'deduplicated';
    case Muted = 'muted';
    case NotInChannels = 'not_in_channels';
    case NoAccess = 'no_access';
    case Duplicate = 'duplicate';
    case Queued = 'queued';
}
