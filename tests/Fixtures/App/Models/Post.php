<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Notifications\Traits\IsNotificationSubject;

/**
 * A subject without reminder rules that still cleans up its inbox items when deleted.
 *
 * @property int $id
 * @property int $author_id
 * @property string $title
 * @property bool $private
 */
class Post extends Model
{
    use IsNotificationSubject;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['private' => 'boolean'];
    }
}
