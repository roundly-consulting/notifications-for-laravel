<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RoundlyConsulting\Notifications\Contracts\HasNotificationContext;
use RoundlyConsulting\Notifications\Contracts\ProvidesNotificationRecipients;

/**
 * A company: an inbox context ("Acme s.r.o.") and a group whose members have roles.
 *
 * @property int $id
 * @property string $name
 */
class Company extends Model implements HasNotificationContext, ProvidesNotificationRecipients
{
    protected $guarded = [];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }

    public function notificationRecipients(?string $role = null): iterable
    {
        $query = $this->users();

        if ($role !== null) {
            $query->wherePivot('role', $role);
        }

        return $query->get();
    }

    public function notificationContextLabel(): string
    {
        return $this->name;
    }

    public function addMember(User $user, ?string $role = null): self
    {
        $this->users()->attach($user->id, ['role' => $role]);

        return $this;
    }
}
