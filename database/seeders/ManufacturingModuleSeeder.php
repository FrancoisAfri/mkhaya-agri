<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Modules\Operations\Manufacturing\Support\ManufacturingHubs;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Registers Manufacturing & Production under "Operations & Support": the module row, its nav
 * ribbons and the permission set (MFG-001.09).
 *
 * Idempotent, and re-running prunes ribbons that have been retired from ManufacturingHubs — the
 * hub config is the single source of truth for navigation, so the menu and the tier enforcement
 * can never drift apart.
 *
 * Roles are not invented here. Role design belongs to the tenant, and creating a "Production
 * Manager" role puts users in a group nobody asked for; the existing platform admin roles get the
 * whole set so the module is reachable on an install that has them.
 */
class ManufacturingModuleSeeder extends Seeder
{
    private const LEVEL = 5; // SUPERUSER

    private const MODULE_CODE = 'manufacturing';

    public function run(): void
    {
        foreach (RoleCatalog::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (['administrator', 'super admin'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()
                ?->givePermissionTo(RoleCatalog::all());
        }

        $category = Category::firstOrCreate(
            ['name' => 'Operations & Support'],
            ['active' => 1, 'description' => 'Operations & Support', 'icon' => 'settings']
        );

        $module = Modules::firstOrNew(['code_name' => self::MODULE_CODE]);
        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }
        $module->fill([
            'active' => 1,
            'category_id' => $category->id,
            'name' => 'Manufacturing & Production',
            'path' => '/manufacturing',
            'font_awesome' => 'building-factory',
        ])->save();

        $ribbons = ManufacturingHubs::navRibbons();

        $ribbonIds = [];
        foreach ($ribbons as $i => $r) {
            $ribbon = Ribbon::firstOrNew(['module_id' => $module->id, 'ribbon_name' => $r['name']]);
            if (! $ribbon->exists) {
                $ribbon->uuid = (string) Str::uuid();
            }
            $ribbon->fill([
                'active' => 1,
                'sort_order' => $i + 1,
                'ribbon_path' => $r['path'],
                'description' => $r['name'],
                'font_awesome' => $r['icon'],
                'access_level' => $r['access_level'] ?? self::LEVEL,
            ])->save();
            $ribbonIds[] = $ribbon->id;
        }

        $stale = Ribbon::where('module_id', $module->id)
            ->whereNotIn('ribbon_name', array_column($ribbons, 'name'))
            ->pluck('id');
        if ($stale->isNotEmpty()) {
            RibbonAccess::whereIn('ribbon_id', $stale)->delete();
            Ribbon::whereIn('id', $stale)->delete();
        }

        $this->grantAccess($category, $module, $ribbonIds);

        $this->command?->info('Manufacturing & Production registered under Operations & Support.');
    }

    /**
     * Grant access to everyone who already reaches another Operations & Support module, so the
     * module appears for the people who work in that area. Falls back to all users on an install
     * where nothing in the category has been granted yet.
     */
    private function grantAccess(Category $category, Modules $module, array $ribbonIds): void
    {
        $categoryModuleIds = Modules::where('category_id', $category->id)->pluck('id');

        $userIds = ModuleAccess::whereIn('module_id', $categoryModuleIds)
            ->where('access_level', '>', 0)
            ->distinct()
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            $userIds = DB::table('users')->pluck('id');
        }

        // Access rows FK to users; an id left over from a deleted user would fail the insert.
        $userIds = $userIds->intersect(DB::table('users')->pluck('id'))->values();

        foreach ($userIds as $userId) {
            CategoryAccess::firstOrCreate(
                ['category_id' => $category->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => self::LEVEL]
            );
            ModuleAccess::firstOrCreate(
                ['module_id' => $module->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => self::LEVEL]
            );
            foreach ($ribbonIds as $ribbonId) {
                RibbonAccess::firstOrCreate(
                    ['ribbon_id' => $ribbonId, 'user_id' => $userId],
                    ['active' => 1, 'access_level' => self::LEVEL]
                );
            }
        }
    }
}
