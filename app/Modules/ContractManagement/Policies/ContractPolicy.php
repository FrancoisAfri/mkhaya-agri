<?php

namespace App\Modules\ContractManagement\Policies;

use App\Core\User\Models\User;

class ContractPolicy
{
    /**
     * Grant all abilities to platform / master admins and privileged roles.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_platform_admin || $user->is_master_admin) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['administrator', 'super admin'])) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('contracts.view');
    }

    public function view(User $user): bool
    {
        return $user->can('contracts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('contracts.create');
    }

    public function update(User $user): bool
    {
        return $user->can('contracts.update');
    }

    public function delete(User $user): bool
    {
        return $user->can('contracts.delete');
    }

    public function approve(User $user): bool
    {
        return $user->can('contracts.approve');
    }
}
