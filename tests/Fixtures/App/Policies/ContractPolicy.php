<?php

declare(strict_types=1);

namespace RoundlyConsulting\Notifications\Tests\Fixtures\App\Policies;

use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\Contract;
use RoundlyConsulting\Notifications\Tests\Fixtures\App\Models\User;

final class ContractPolicy
{
    public function view(User $user, Contract $contract): bool
    {
        return AppPolicies::member($user, $contract->company_id);
    }
}
