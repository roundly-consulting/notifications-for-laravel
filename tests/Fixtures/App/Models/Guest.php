<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * A notifiable that is not a recipient in the package's sense (no ReceivesNotifications) — a
 * ticket guest, a contact.
 *
 * @property int $id
 * @property string|null $email
 */
class Guest extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $guarded = [];
}
