<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Production promised to customers (MFG-017). Committing raises orders and holds stock, so it is its own grant. */
class CustomerCommitmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_CUSTOMER_ORDERS);
    }

    public function commit(User $user): bool
    {
        return $user->can(RoleCatalog::COMMIT_CUSTOMER_ORDERS);
    }
}
