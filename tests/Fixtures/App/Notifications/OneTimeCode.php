<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A time-critical notification: a subclass of Laravel's password reset.
 */
class OneTimeCode extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your code '.$this->token);
    }
}
