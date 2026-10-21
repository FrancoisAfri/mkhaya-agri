<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Recommendation;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Outside sources, outbreaks, opportunities, forecasts and the agents' recommendations. */
class IntelligencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_INTELLIGENCE) || $user->can(RoleCatalog::MANAGE_INTELLIGENCE) || $user->can(RoleCatalog::DECIDE_RECOMMENDATIONS);
    }

    public function view(User $user, Recommendation $r): bool
    {
        return $this->viewAny($user);
    }

    public function decide(User $user): bool
    {
        return $user->can(RoleCatalog::DECIDE_RECOMMENDATIONS);
    }

    public function manage(User $user): bool
    {
        return $user->can(RoleCatalog::MANAGE_INTELLIGENCE);
    }
}
