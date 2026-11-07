<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Models;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RoundlyConsulting\Notifications\Traits\ReceivesNotifications;

/**
 * The test app's user: Laravel's Notifiable (mail routing) beside the package's
 * ReceivesNotifications, a locale preference and an optional timezone column.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $locale
 * @property string|null $timezone
 */
class User extends Authenticatable implements HasLocalePreference
{
    use Notifiable;
    use ReceivesNotifications;

    protected $guarded = [];

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withPivot('role');
    }
}
