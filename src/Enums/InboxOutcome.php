<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

/**
 * What one send did to one recipient's inbox.
 */
enum InboxOutcome: string
{
    case Created = 'created';
    case Collapsed = 'collapsed';
    case Muted = 'muted';
    case NotInChannels = 'not_in_channels';
    case NoAccess = 'no_access';
    case Duplicate = 'duplicate';
    case Queued = 'queued';
}
