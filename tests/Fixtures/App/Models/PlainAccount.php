<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A recipient that cannot route mail (no Notifiable).
 *
 * @property int $id
 */
class PlainAccount extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
