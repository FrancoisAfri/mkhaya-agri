<?php

namespace App\Modules\Operations\Manufacturing\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/** Loss prevention (MFG-012): reading findings, working them, and management's approval. */
class LossFindingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_LOSS);
    }

    public function rules(User $user): bool
    {
        return $user->can(RoleCatalog::VIEW_LOSS);
    }

    public function investigate(User $user): bool
    {
        return $user->can(RoleCatalog::INVESTIGATE_LOSS);
    }

    public function approve(User $user): bool
    {
        return $user->can(RoleCatalog::APPROVE_LOSS);
    }
}
