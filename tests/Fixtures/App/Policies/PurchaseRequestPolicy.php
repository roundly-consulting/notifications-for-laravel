<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Policies;

use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\PurchaseRequest;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

final class PurchaseRequestPolicy
{
    public function view(User $user, PurchaseRequest $request): bool
    {
        return AppPolicies::member($user, $request->company_id);
    }
}
