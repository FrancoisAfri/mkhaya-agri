<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * The factory environment (MFG-001). Named FactoryPolicyRules because FactoryPolicy is already a
 * model -- the tolerances and switches a factory is configured with.
 *
 * `configure` is a single ability covering factories, work centres, shifts, units, numbering and
 * tolerances. They are one job done by one person, and splitting them into six permissions would
 * produce five ways to half-configure a plant.
 */
class FactoryPolicyRules
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_DASHBOARD) || $user->can(RoleCatalog::CONFIGURE);
    }

    public function view(User $user, Factory $factory): bool
    {
        return $this->viewAny($user);
    }

    public function configure(User $user): bool
    {
        return $user->can(RoleCatalog::CONFIGURE);
    }

    public function create(User $user): bool
    {
        return $user->can(RoleCatalog::CONFIGURE);
    }

    public function update(User $user, Factory $factory): bool
    {
        return $user->can(RoleCatalog::CONFIGURE);
    }

    public function delete(User $user, Factory $factory): bool
    {
        return $user->can(RoleCatalog::CONFIGURE);
    }
}
