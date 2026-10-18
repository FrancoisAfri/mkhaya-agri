<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * Inspecting and deciding are different permissions (MFG-009): whoever measured a lot does not, by
 * measuring it, get to release it.
 */
class InspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_QUALITY);
    }

    public function view(User $user, Inspection $inspection): bool
    {
        return $user->can(RoleCatalog::VIEW_QUALITY);
    }

    /** Receiving material for inspection, or putting stock on hold pending one. */
    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::INSPECT);
    }

    public function record(User $user, Inspection $inspection): bool
    {
        return $inspection->status === Inspection::OPEN && $user->can(RoleCatalog::INSPECT);
    }

    public function dispose(User $user, Inspection $inspection): bool
    {
        return $inspection->status === Inspection::AWAITING_DISPOSITION && $user->can(RoleCatalog::DISPOSE);
    }

    /** Filing certificates and reports, while the inspection is still being worked. */
    public function attach(User $user, Inspection $inspection): bool
    {
        return $user->can(RoleCatalog::INSPECT) || $user->can(RoleCatalog::DISPOSE);
    }
}
