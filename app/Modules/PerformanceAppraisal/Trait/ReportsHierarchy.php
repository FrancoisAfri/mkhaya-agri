<?php

namespace App\Modules\PerformanceAppraisal\Trait;

use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

trait ReportsHierarchy
{
    protected const ADMIN_ROLES   = ['super admin', 'administrator', 'HR'];
    protected const MANAGER_ROLES = ['team leader', 'supervisor', 'manager', 'compliance officer', 'senior', 'senior manager', 'executive'];

    /**
     * Returns the set of user IDs the current user may view records for.
     * null → no restriction (admin/HR); array → explicit whitelist.
     */
    protected function getAllowedEmployeeIds(User $user): ?array
    {
        if ($user->hasRole(self::ADMIN_ROLES)) {
            return null;
        }

        if ($user->hasRole(self::MANAGER_ROLES)) {
            return array_values(array_unique(
                array_merge([$user->id], $this->getSubtreeUserIds($user->id))
            ));
        }

        return [$user->id];
    }

    /**
     * Recursively walks the hr_people manager chain downward from $userId
     * and returns all subordinate user IDs (excluding $userId itself).
     */
    protected function getSubtreeUserIds(int $userId): array
    {
        $myPersonId = DB::table('hr_people')->where('user_id', $userId)->value('id');
        if (!$myPersonId) {
            return [];
        }

        $personIds = [$myPersonId];
        $queue     = [$myPersonId];

        while (!empty($queue)) {
            $children = DB::table('hr_people')
                ->whereIn('manager_id', $queue)
                ->pluck('id')
                ->toArray();

            $newChildren = array_diff($children, $personIds);
            if (empty($newChildren)) {
                break;
            }

            $personIds = array_merge($personIds, $newChildren);
            $queue     = array_values($newChildren);
        }

        return DB::table('hr_people')
            ->whereIn('id', $personIds)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $userId)
            ->pluck('user_id')
            ->toArray();
    }
}
