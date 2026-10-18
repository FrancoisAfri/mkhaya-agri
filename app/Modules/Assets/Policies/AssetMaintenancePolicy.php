<?php

namespace App\Modules\Assets\Policies;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;

class AssetMaintenancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function view(User $user, AssetMaintenanceSchedule $schedule): bool
    {
        return $user->hasPermissionTo('assets.view');
    }

    public function schedule(User $user): bool
    {
        return $user->hasPermissionTo('assets.maintenance.create')
            || $user->hasPermissionTo('assets.maintenance');
    }

    public function complete(User $user, AssetMaintenanceSchedule $schedule): bool
    {
        if (! $user->hasPermissionTo('assets.maintenance.complete')) {
            return false;
        }

        return in_array($schedule->status, ['scheduled', 'in_progress'], true);
    }

    public function cancel(User $user, AssetMaintenanceSchedule $schedule): bool
    {
        return $user->hasPermissionTo('assets.maintenance')
            && in_array($schedule->status, ['scheduled', 'in_progress'], true);
    }
}
