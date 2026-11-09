<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

/**
 * What redeeming an unsubscribe link did. Every outcome is final: redeeming the link again
 * answers the same, or AlreadyUnsubscribed.
 */
enum UnsubscribeOutcome: string
{
    /** The type's mail is now off for the recipient. */
    case Unsubscribed = 'unsubscribed';

    /** It was off already (or the type sends no mail): nothing changed. */
    case AlreadyUnsubscribed = 'already_unsubscribed';

    /** The type is mandatory now: its mail cannot be switched off. */
    case Required = 'required';

    /** The recipient or the type no longer exists: no mail can come. */
    case Gone = 'gone';
}
