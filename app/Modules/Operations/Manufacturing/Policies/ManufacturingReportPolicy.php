<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * Who sees which dashboard (MFG-018). Each follows the permission for the figures it shows, so a
 * dashboard never reveals what the screens behind it would refuse.
 */
class ManufacturingReportPolicy
{
    /** Output, margin, variances and savings: management with cost visibility. */
    public function executive(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_COSTS);
    }

    public function planning(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_PLANNING);
    }

    public function inventory(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_ORDERS);
    }

    public function costs(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_COSTS);
    }

    /** The audit trail of manufacturing records (MFG-023). */
    public function audit(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_AUDIT);
    }

    /** Either half: a quality manager sees quality, a maintenance planner sees maintenance. */
    public function quality(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_QUALITY) || $user->can(RoleCatalog::VIEW_MAINTENANCE);
    }
}
