<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

/**
 * Why a delivery or reminder ended without sending.
 */
enum CancelReason: string
{
    case RecipientGone = 'recipient_gone';
    case UnknownType = 'unknown_type';
    case UnknownRule = 'unknown_rule';
    case SubjectGone = 'subject_gone';
    case SubjectDeleted = 'subject_deleted';
    case NoAccess = 'no_access';
    case Muted = 'muted';
    case Revoked = 'revoked';
    case AnchorCleared = 'anchor_cleared';
    case Passed = 'passed';
    case Condition = 'condition';
    case Acknowledged = 'acknowledged';
    case Replaced = 'replaced';
    case Cancelled = 'cancelled';
    case Late = 'late';
    case Transport = 'transport';
    case Render = 'render';
    case Unknown = 'unknown';
    case Payload = 'payload';
    case NoAddress = 'no_address';
    case Suppressed = 'suppressed';
    case RateLimited = 'rate_limited';
}
