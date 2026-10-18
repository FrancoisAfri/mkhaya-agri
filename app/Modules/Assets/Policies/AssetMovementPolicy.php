<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetMovement;

class AssetMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function view(User $user, AssetMovement $movement): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function record(User $user): bool
    {
        return $user->hasPermissionTo('assets.movements');
    }
}
