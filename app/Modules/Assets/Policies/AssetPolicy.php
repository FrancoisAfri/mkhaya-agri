<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\Assets;

class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function view(User $user, Assets $asset): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('assets.create');
    }

    public function update(User $user, Assets $asset): bool
    {
        if (in_array($asset->asset_status, ['disposed', 'sold'], true)) {
            return false;
        }

        return $user->hasPermissionTo('assets.update') || $user->hasPermissionTo('assets.edit');
    }

    public function delete(User $user, Assets $asset): bool
    {
        return $user->hasPermissionTo('assets.delete') && $asset->asset_status !== 'active';
    }

    public function dispose(User $user, Assets $asset): bool
    {
        if (! $user->hasPermissionTo('assets.dispose')) {
            return false;
        }

        return ! in_array($asset->asset_status, ['disposed', 'sold'], true);
    }

    public function transfer(User $user, Assets $asset): bool
    {
        return $user->hasPermissionTo('assets.transfer')
            && ! in_array($asset->asset_status, ['disposed', 'sold', 'missing'], true);
    }

    public function audit(User $user): bool
    {
        return $user->hasPermissionTo('assets.audit') || $user->hasPermissionTo('assets.view');
    }
}
