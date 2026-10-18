<?php

namespace App\Modules\Admin\UserAccess\Services;

use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

class UserAccessService
{
    public function getUsersAccess()
    {
        return User::with(['person', 'contactPerson', 'roles'])
            ->orderBy('type')
            ->get();
    }

    public function getUserAccess($userId)
    {
        $categories = DB::table('security_categories')
            ->leftJoin('security_category_access', function ($join) use ($userId) {
                $join->on('security_categories.id', '=', 'security_category_access.category_id')
                    ->where('security_category_access.user_id', $userId);
            })
            ->select(
                'security_category_access.id',
                'security_categories.id as category_id',
                'security_categories.name as category_name',
                'security_categories.icon as category_icon',
                'security_category_access.access_level',
                'security_category_access.active'
            )
            ->get();

        $modules = DB::table('security_modules')
            ->leftJoin('security_module_access', function ($join) use ($userId) {
                $join->on('security_modules.id', '=', 'security_module_access.module_id')
                    ->where('security_module_access.user_id', $userId);
            })
            ->select(
                'security_module_access.id',
                'security_modules.id as module_id',
                'security_modules.name as module_name',
                'security_modules.code_name as module_code',
                'security_modules.font_awesome as module_icon',
                'security_modules.category_id',
                'security_module_access.access_level'
            )
            ->get();

        // Only expose modules the company's plan (SaaS) or licence (self-hosted)
        // actually covers — admins can't grant access to modules the tenant lacks.
        $ent = app(\App\Modules\Tenancy\Services\CompanyEntitlements::class);
        $modules = $modules->filter(fn ($m) => $ent->allowsNavModule($m->module_code))->values();
        $visibleModuleIds = $modules->pluck('module_id')->all();
        $visibleCategoryIds = $modules->pluck('category_id')->unique()->all();

        $ribbons = DB::table('security_module_ribbons')
            ->leftJoin('security_ribbons_access', function ($join) use ($userId) {
                $join->on('security_module_ribbons.id', '=', 'security_ribbons_access.ribbon_id')
                    ->where('security_ribbons_access.user_id', $userId);
            })
            ->select(
                'security_ribbons_access.id',
                'security_module_ribbons.id as ribbon_id',
                'security_module_ribbons.ribbon_name',
                'security_module_ribbons.font_awesome',
                'security_module_ribbons.module_id',
                'security_ribbons_access.access_level',
                'security_ribbons_access.active'
            )
            ->get();

        // Ribbons follow their module; categories keep only those with a visible module.
        $ribbons = $ribbons->filter(fn ($r) => in_array($r->module_id, $visibleModuleIds, true))->values();
        $categories = $categories->filter(fn ($c) => in_array($c->category_id, $visibleCategoryIds, true))->values();

        return ['categories' => $categories, 'modules' => $modules, 'ribbons' => $ribbons];
    }

    public function saveCategoryAccess($userId, $categoryId, $accessLevel)
    {
        return CategoryAccess::updateOrInsert(
            ['user_id' => $userId, 'category_id' => $categoryId],
            ['access_level' => $accessLevel, 'active' => $accessLevel > 0]
        );
    }

    public function saveModuleAccess(int $userId, int $moduleId, int $accessLevel): array
    {
        // Guard: don't let a tenant grant access to a module its plan/licence lacks.
        $code = DB::table('security_modules')->where('id', $moduleId)->value('code_name');
        if (! app(\App\Modules\Tenancy\Services\CompanyEntitlements::class)->allowsNavModule($code)) {
            return ['success' => false, 'message' => 'This module is not part of your subscription.'];
        }

        ModuleAccess::updateOrCreate(
            ['user_id' => $userId, 'module_id' => $moduleId],
            ['access_level' => $accessLevel, 'active' => $accessLevel > 0]
        );
        return ['success' => true];
    }

    public function saveRibbonAccess(int $userId, int $ribbonId, int $accessLevel): array
    {
        RibbonAccess::updateOrCreate(
            ['user_id' => $userId, 'ribbon_id' => $ribbonId],
            ['access_level' => $accessLevel, 'active' => $accessLevel > 0]
        );
        return ['success' => true];
    }
}
