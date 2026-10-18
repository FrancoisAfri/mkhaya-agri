<?php

namespace App\Modules\HR\Contracts\Policies;

use App\Core\User\Models\User;
use App\Modules\HR\Contracts\Models\Contract;

class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('contracts.view');
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->hasPermissionTo('contracts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('contracts.create');
    }

    public function send(User $user, Contract $contract): bool
    {
        return $user->hasPermissionTo('contracts.send');
    }
}
