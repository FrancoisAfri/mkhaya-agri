<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;

class AssetDepreciationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function run(User $user): bool
    {
        return $user->hasPermissionTo('assets.depreciation');
    }

    public function override(User $user): bool
    {
        return $user->hasPermissionTo('assets.depreciation')
            && $user->hasPermissionTo('assets.approve');
    }

    public function post(User $user): bool
    {
        return $user->hasPermissionTo('assets.depreciation.post')
            || $user->hasPermissionTo('finance.gl.post');
    }
}
