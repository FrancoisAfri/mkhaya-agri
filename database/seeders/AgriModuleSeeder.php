<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Modules\Agri\Support\AgriHubs;
use App\Modules\Agri\Support\RoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Registers Mkhaya Agri: its own "Farm Operations" category, the module row, its nav ribbons and
 * the agri.* permission set.
 *
 * Idempotent; re-running prunes ribbons retired from AgriHubs, which is the single source of truth
 * for the menu. Roles are not invented: the platform's administrator roles get the whole set
 * (including "all farms"), and the tenant decides who else gets what.
 */
class AgriModuleSeeder extends Seeder
{
    private const LEVEL = 5; // SUPERUSER

    private const MODULE_CODE = 'agri';

    public function run(): void
    {
        foreach (RoleCatalog::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['administrator', 'super admin'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()
                ?->givePermissionTo(RoleCatalog::all());
        }

        $category = Category::firstOrCreate(
            ['name' => 'Farm Operations'],
            ['active' => 1, 'description' => 'Farms, crops, livestock and field work', 'icon' => 'plant-2']
        );

        $module = Modules::firstOrNew(['code_name' => self::MODULE_CODE]);
        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }
        $module->fill([
            'active' => 1,
            'category_id' => $category->id,
            'name' => 'Farm Management',
            'path' => '/agri',
            'font_awesome' => 'plant-2',
        ])->save();

        $ribbons = AgriHubs::navRibbons();
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

        $this->command?->info('Mkhaya Agri registered under Farm Operations.');
    }

    /**
     * The menu entry is shown to every existing user; what each of them can then open is decided
     * by the agri.* permissions and their farm memberships, which grant nothing by default.
     */
    private function grantAccess(Category $category, Modules $module, array $ribbonIds): void
    {
        $userIds = DB::table('users')->whereNull('deleted_at')->pluck('id');

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
