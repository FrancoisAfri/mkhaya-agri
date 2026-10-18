<?php

namespace App\Modules\SupplierManagement\Policies;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\Models\Supplier;

class SupplierPolicy
{
    /**
     * Platform / master admins and high-level roles bypass all checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ((method_exists($user, 'isPlatformAdmin') && $user->isPlatformAdmin())
            || (method_exists($user, 'isMasterAdmin') && $user->isMasterAdmin())) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['administrator', 'super admin'])) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function view(User $user, ?Supplier $supplier = null): bool
    {
        return $user->can('suppliers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('suppliers.create');
    }

    public function update(User $user, ?Supplier $supplier = null): bool
    {
        return $user->can('suppliers.update');
    }

    public function delete(User $user, ?Supplier $supplier = null): bool
    {
        return $user->can('suppliers.delete');
    }

    /**
     * Used for KYC decisions and supplier activation.
     */
    public function approve(User $user, ?Supplier $supplier = null): bool
    {
        return $user->can('suppliers.approve');
    }
}
