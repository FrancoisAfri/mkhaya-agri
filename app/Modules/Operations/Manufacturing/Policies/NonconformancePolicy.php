<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\NcrAction;
use App\Modules\Operations\Manufacturing\Models\Nonconformance;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * Raising an NCR is open to anybody who inspects -- a defect found should never wait for a
 * manager. Working and closing it is the quality team's.
 */
class NonconformancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_QUALITY);
    }

    public function view(User $user, Nonconformance $ncr): bool
    {
        return $user->can(RoleCatalog::VIEW_QUALITY);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_NCR) || $user->can(RoleCatalog::INSPECT);
    }

    public function update(User $user, Nonconformance $ncr): bool
    {
        return $ncr->isOpen() && $user->can(RoleCatalog::MANAGE_NCR);
    }

    /** An action's owner may mark their own action done; otherwise the quality team. */
    public function completeAction(User $user, Nonconformance $ncr, ?NcrAction $action = null): bool
    {
        return $ncr->isOpen() && ($user->can(RoleCatalog::MANAGE_NCR) || ($action && (int) $action->owner_id === (int) $user->id));
    }

    public function verify(User $user, Nonconformance $ncr): bool
    {
        return $ncr->status === Nonconformance::VERIFICATION && $user->can(RoleCatalog::MANAGE_NCR);
    }
}
