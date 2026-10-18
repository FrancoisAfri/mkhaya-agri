<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Operations\WarehouseManagement\Support\WarehouseHubs;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Registers Warehouse Management & Intelligence under "Operations & Support": the module row, its
 * ribbons (derived from WarehouseHubs, pruning retired ones) and its permission set.
 *
 * Idempotent: every later WHMI part re-runs it from its own migration so new hubs and permissions
 * reach existing installs.
 */
class WarehouseModuleSeeder extends Seeder
{
    private const LEVEL = 5;

    private const MODULE_CODE = 'warehouse_management';

    public function run(): void
    {
        foreach (RoleCatalog::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (['administrator', 'super admin'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()
                ?->givePermissionTo(RoleCatalog::all());
        }

        // The warehouse role templates (WMS-200); a preset somebody changed is left as it is.
        \App\Modules\Operations\WarehouseManagement\Support\RolePresets::install();

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
            'name' => 'Warehouse Management & Intelligence',
            'path' => '/warehouse',
            'font_awesome' => 'building-warehouse',
        ])->save();

        $ribbons = WarehouseHubs::navRibbons();
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

        $stale = Ribbon::where('module_id', $module->id)->whereNotIn('ribbon_name', array_column($ribbons, 'name'))->pluck('id');
        if ($stale->isNotEmpty()) {
            RibbonAccess::whereIn('ribbon_id', $stale)->delete();
            Ribbon::whereIn('id', $stale)->delete();
        }

        $this->grantAccess($category, $module, $ribbonIds);
    }

    /** Everyone who already reaches an Operations & Support module, or everybody on a fresh install. */
    private function grantAccess(Category $category, Modules $module, array $ribbonIds): void
    {
        $userIds = ModuleAccess::whereIn('module_id', Modules::where('category_id', $category->id)->pluck('id'))
            ->where('access_level', '>', 0)->distinct()->pluck('user_id');

        if ($userIds->isEmpty()) {
            $userIds = DB::table('users')->pluck('id');
        }
        $userIds = $userIds->intersect(DB::table('users')->pluck('id'))->values();

        foreach ($userIds as $userId) {
            CategoryAccess::firstOrCreate(['category_id' => $category->id, 'user_id' => $userId], ['active' => 1, 'access_level' => self::LEVEL]);
            ModuleAccess::firstOrCreate(['module_id' => $module->id, 'user_id' => $userId], ['active' => 1, 'access_level' => self::LEVEL]);
            foreach ($ribbonIds as $ribbonId) {
                RibbonAccess::firstOrCreate(['ribbon_id' => $ribbonId, 'user_id' => $userId], ['active' => 1, 'access_level' => self::LEVEL]);
            }
        }
    }
}
