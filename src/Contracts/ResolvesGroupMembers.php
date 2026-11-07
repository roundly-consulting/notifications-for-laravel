<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves Recipients::role() and Recipients::members(): the members of a group, optionally only
 * those with a role. Bind your own through `notifications.recipients.group_resolver` (the teams
 * recipe in the docs is twelve lines).
 */
interface ResolvesGroupMembers
{
    /**
     * @return iterable<Model>
     */
    public function members(Model $group, ?string $role = null): iterable;
}
