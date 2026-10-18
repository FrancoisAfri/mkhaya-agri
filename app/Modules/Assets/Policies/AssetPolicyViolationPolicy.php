<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetPolicyException;
use App\Modules\Assets\Models\AssetPolicyViolation;

class AssetPolicyViolationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function view(User $user, AssetPolicyViolation $violation): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function resolve(User $user, AssetPolicyViolation $violation): bool
    {
        return $user->hasPermissionTo('assets.policy.resolve')
            && in_array($violation->status, ['open', 'acknowledged'], true);
    }

    public function requestException(User $user, AssetPolicyViolation $violation): bool
    {
        return $user->hasPermissionTo('assets.view')
            && $violation->status === 'open';
    }

    public function approveException(User $user, AssetPolicyException $exception): bool
    {
        if (! $user->hasPermissionTo('assets.policy.approve-exception')) {
            return false;
        }

        if ((int) $exception->requested_by_user_id === (int) $user->id) {
            return false;
        }

        return $exception->status === 'pending';
    }
}
