<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Manufacturing accounting controls (MFG-021): finance's grants, not production's. */
class AccountingRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_ACCOUNTING);
    }

    public function reconcile(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_ACCOUNTING);
    }

    public function transactions(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_ACCOUNTING);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::APPROVE_ACCOUNTING);
    }

    public function adjust(User $user): bool
    {
        return $user->can(RoleCatalog::ADJUST_ACCOUNTING);
    }
}
