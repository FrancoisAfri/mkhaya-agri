<?php

namespace App\Support\Hubs;

use App\Core\User\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a user's access tier (READ=1 … SUPERUSER=5) for a hubbed module, used to filter hub
 * cards and gate routes. Mirrors the app's ribbon-access model: the tier comes from
 * security_module_access for the module. Platform admins and company owners get SUPERUSER;
 * console/no-user gets SUPERUSER; anyone else with no record falls back to READ.
 *
 * This is the module-parametrised sibling of ContentMarketing's ContentAccess.
 */
class HubAccess
{
    public static function level(?User $user, string $moduleCode): int
    {
        if (! $user) {
            return ModuleHubs::SUPERUSER; // console / commands
        }
        if (($user->is_platform_admin ?? false) || self::isCompanyOwner($user)) {
            return ModuleHubs::SUPERUSER;
        }

        $moduleId = Cache::remember(
            "hub_module_id:{$moduleCode}",
            3600,
            fn () => DB::table('security_modules')->where('code_name', $moduleCode)->value('id')
        );
        if (! $moduleId) {
            return ModuleHubs::READ;
        }

        $level = (int) DB::table('security_module_access')
            ->where('user_id', $user->id)->where('module_id', $moduleId)->value('access_level');

        return $level > 0 ? $level : ModuleHubs::READ;
    }

    private static function isCompanyOwner(User $user): bool
    {
        if (! ($user->company_id ?? null)) {
            return false;
        }
        $owner = (int) DB::table('companies')->where('id', $user->company_id)->value('created_by');

        return $owner === (int) $user->id;
    }
}
