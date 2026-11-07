<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Policies;

use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Post;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

/**
 * The test app's access rules: company members see the company's requests and contracts; a
 * private post is visible to its author only.
 */
final class AppPolicies
{
    public static function member(User $user, int $companyId): bool
    {
        return $user->companies()->whereKey($companyId)->exists();
    }
}
