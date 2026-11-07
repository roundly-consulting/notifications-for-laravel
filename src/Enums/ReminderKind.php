<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Enums;

enum ReminderKind: string
{
    case Reminder = 'reminder';
    case Escalation = 'escalation';
}
