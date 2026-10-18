<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetIncident;

class AssetIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function view(User $user, AssetIncident $incident): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function report(User $user): bool
    {
        return $user->hasPermissionTo('assets.incidents');
    }

    public function resolve(User $user, AssetIncident $incident): bool
    {
        return $user->hasPermissionTo('assets.incidents') && ! $incident->is_resolved;
    }
}
