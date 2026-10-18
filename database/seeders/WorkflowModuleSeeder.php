<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Modules\Workflow\Support\WorkflowHubs;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Registers Workflow & Automation under Operations & Support: permissions, starter role templates,
 * the module row and its ribbons (from WorkflowHubs). Idempotent; later phases re-run it.
 *
 * Module access is navigation only. What a person may do is the workflow.* permissions; the tenant
 * administrator role receives them by default.
 */
class WorkflowModuleSeeder extends Seeder
{
    private const LEVEL = 5;

    private const MODULE_CODE = 'workflow';

    public function run(): void
    {
        foreach (WorkflowPermissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::where('name', 'administrator')->where('guard_name', 'web')->first()?->givePermissionTo(WorkflowPermissions::all());

        foreach (WorkflowPermissions::roleTemplates() as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            // A template somebody already changed is theirs; only a new role gets the starter set.
            if ($role->wasRecentlyCreated) {
                $role->givePermissionTo($permissions);
            }
        }

        $category = Category::firstOrCreate(['name' => 'Operations & Support'], ['active' => 1, 'description' => 'Operations & Support', 'icon' => 'settings']);

        $module = Modules::firstOrNew(['code_name' => self::MODULE_CODE]);
        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }
        $module->fill([
            'active' => 1, 'category_id' => $category->id, 'name' => 'Workflow & Automation',
            'path' => '/workflow', 'font_awesome' => 'hierarchy',
        ])->save();

        $ribbons = WorkflowHubs::navRibbons();
        $ribbonIds = [];
        foreach ($ribbons as $i => $r) {
            $ribbon = Ribbon::firstOrNew(['module_id' => $module->id, 'ribbon_name' => $r['name']]);
            if (! $ribbon->exists) {
                $ribbon->uuid = (string) Str::uuid();
            }
            $ribbon->fill([
                'active' => 1, 'sort_order' => $i + 1, 'ribbon_path' => $r['path'], 'description' => $r['name'],
                'font_awesome' => $r['icon'], 'access_level' => $r['access_level'] ?? self::LEVEL,
            ])->save();
            $ribbonIds[] = $ribbon->id;
        }

        $stale = Ribbon::where('module_id', $module->id)->whereNotIn('ribbon_name', array_column($ribbons, 'name'))->pluck('id');
        if ($stale->isNotEmpty()) {
            RibbonAccess::whereIn('ribbon_id', $stale)->delete();
            Ribbon::whereIn('id', $stale)->delete();
        }

        // Navigation for whoever already reaches Operations & Support modules.
        $userIds = ModuleAccess::whereIn('module_id', Modules::where('category_id', $category->id)->pluck('id'))
            ->where('access_level', '>', 0)->distinct()->pluck('user_id')
            ->intersect(DB::table('users')->pluck('id'))->values();

        foreach ($userIds as $userId) {
            CategoryAccess::firstOrCreate(['category_id' => $category->id, 'user_id' => $userId], ['active' => 1, 'access_level' => self::LEVEL]);
            ModuleAccess::firstOrCreate(['module_id' => $module->id, 'user_id' => $userId], ['active' => 1, 'access_level' => self::LEVEL]);
            foreach ($ribbonIds as $ribbonId) {
                RibbonAccess::firstOrCreate(['ribbon_id' => $ribbonId, 'user_id' => $userId], ['active' => 1, 'access_level' => self::LEVEL]);
            }
        }
    }
}
