<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use RoundlyConsulting\Notifications\Traits\ReceivesNotifications;

/**
 * A ULID-keyed recipient (`notifications.key_type` = ulid).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 */
class UlidMember extends Model
{
    use HasUlids;
    use Notifiable;
    use ReceivesNotifications;

    protected $guarded = [];
}
