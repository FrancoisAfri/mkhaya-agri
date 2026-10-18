<?php

namespace App\Core\Permission\Services;

use App\Core\Permission\Model\ModuleRibbon;
use App\Core\Permission\Model\UserModuleAccess;
use Illuminate\Database\Eloquent\Collection;

class ModuleService
{
    /**
     * Grant a user access to a specific module with a given access level.
     *
     * @param int $userId
     * @param int $moduleId
     * @param int $accessLevel
     * @return UserModuleAccess
     */
    public static function giveUserAccess(int $userId, int $moduleId, int $accessLevel): UserModuleAccess
    {
        return UserModuleAccess::updateOrCreate(
            [
                'user_id' => $userId,
                'module_id' => $moduleId,
            ],
            [
                'access_level' => $accessLevel,
            ]
        );
    }

    /**
     * Revoke a user's access to a module.
     *
     * @param int $userId
     * @param int $moduleId
     * @return bool
     */
    public static function revokeUserAccess(int $userId, int $moduleId): bool
    {
        return UserModuleAccess::where('user_id', $userId)
                ->where('module_id', $moduleId)
                ->delete() > 0;
    }

    /**
     * Get all modules a user has access to, with their access levels.
     *
     * @param int $userId
     * @return Collection
     */
    public static function getUserModules(int $userId)
    {
        return UserModuleAccess::with('module')
            ->where('user_id', $userId)
            ->get();
    }

    /**
     * Check if a user has access to a module with at least a minimum access level.
     *
     * @param int $userId
     * @param int $moduleId
     * @param int $minLevel
     * @return bool
     */
    public static function hasAccess(int $userId, int $moduleId, int $minLevel = 1): bool
    {
        $access = UserModuleAccess::where('user_id', $userId)
            ->where('module_id', $moduleId)
            ->first();

        return $access && $access->access_level >= $minLevel;
    }

    /**
     * Check if a user has access to a module with at least a minimum access level.
     */
    public static function hasModuleAccess(int $userId, int $moduleId, int $minLevel = 1): bool
    {
        $access = UserModuleAccess::where('user_id', $userId)
            ->where('module_id', $moduleId)
            ->where('active', 1)
            ->first();

        return $access && $access->access_level >= $minLevel;
    }

    /**
     * Get all ribbons for a module that a user can access based on their module access level.
     */
    public static function getUserRibbons(int $userId, int $moduleId)
    {
        $access = UserModuleAccess::where('user_id', $userId)
            ->where('module_id', $moduleId)
            ->where('active', 1)
            ->first();

        if (!$access) {
            return collect(); // no access to module
        }

        return ModuleRibbon::where('module_id', $moduleId)
            ->where('active', 1)
            ->where('access_level', '<=', $access->access_level)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Check if a user can access a specific ribbon.
     */
    public static function canAccessRibbon(int $userId, int $ribbonId): bool
    {
        $ribbon = ModuleRibbon::find($ribbonId);
        if (!$ribbon || !$ribbon->active) {
            return false;
        }

        $access = UserModuleAccess::where('user_id', $userId)
            ->where('module_id', $ribbon->module_id)
            ->where('active', 1)
            ->first();

        return $access && $access->access_level >= $ribbon->access_level;
    }
}
