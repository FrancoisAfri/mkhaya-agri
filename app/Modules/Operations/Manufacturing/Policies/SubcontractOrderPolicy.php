<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Work sent to suppliers (MFG-017). Sending issues material and raises a purchase request. */
class SubcontractOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_SUBCONTRACT);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_SUBCONTRACT);
    }
}
