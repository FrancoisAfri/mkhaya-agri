<?php

namespace App\Modules\Procurement\Policies;

use App\Core\User\Models\User;

class PurchaseRequestPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (($user->is_platform_admin ?? false) || ($user->is_master_admin ?? false)) {
            return true;
        }
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['administrator', 'super admin'])) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('procurement.view');
    }

    public function view(User $user): bool
    {
        return $user->can('procurement.view');
    }

    public function create(User $user): bool
    {
        return $user->can('procurement.create');
    }

    public function update(User $user): bool
    {
        return $user->can('procurement.update');
    }

    public function delete(User $user): bool
    {
        return $user->can('procurement.delete');
    }

    public function approve(User $user): bool
    {
        return $user->can('procurement.approve');
    }
}
