<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Modules\Operations\ProjectManagement\Support\ProjectHubs;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Registers Project Management under "Operations & Support": the module row, its nav ribbons, the
 * permission set, and access for the users who should already have it. Idempotent — safe to re-run,
 * and re-running prunes ribbons that have been retired from ProjectHubs.
 */
class ProjectManagementModuleSeeder extends Seeder
{
    private const LEVEL = 5; // SUPERUSER

    private const MODULE_CODE = 'project_management';

    /**
     * The module's permission set — sourced from RoleCatalog so the roles, the policies and the
     * Spatie permissions can never describe different systems.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        return RoleCatalog::ALL;
    }

    /** Kept for callers that referenced the old constant. */
    public const PERMISSIONS = RoleCatalog::ALL;

    public function run(): void
    {
        $this->seedPermissions();

        $category = Category::firstOrCreate(
            ['name' => 'Operations & Support'],
            ['active' => 1, 'description' => 'Operations & Support', 'icon' => 'settings']
        );

        $module = Modules::firstOrNew(['code_name' => self::MODULE_CODE]);
        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }
        $module->fill([
            'active'       => 1,
            'category_id'  => $category->id,
            'name'         => 'Project Management',
            'path'         => '/project-management',
            'font_awesome' => 'subtask',
        ])->save();

        // Single source of truth for navigation: the hub config, so nav and tier enforcement can
        // never drift apart.
        $ribbons = ProjectHubs::navRibbons();

        $ribbonIds = [];
        foreach ($ribbons as $i => $r) {
            $ribbon = Ribbon::firstOrNew(['module_id' => $module->id, 'ribbon_name' => $r['name']]);
            if (! $ribbon->exists) {
                $ribbon->uuid = (string) Str::uuid();
            }
            $ribbon->fill([
                'active'       => 1,
                'sort_order'   => $i + 1,
                'ribbon_path'  => $r['path'],
                'description'  => $r['name'],
                'font_awesome' => $r['icon'],
                'access_level' => $r['access_level'] ?? self::LEVEL,
            ])->save();
            $ribbonIds[] = $ribbon->id;
        }

        // Prune ribbons retired from ProjectHubs, so a rename does not leave a dead nav entry.
        $stale = Ribbon::where('module_id', $module->id)
            ->whereNotIn('ribbon_name', array_column($ribbons, 'name'))
            ->pluck('id');
        if ($stale->isNotEmpty()) {
            RibbonAccess::whereIn('ribbon_id', $stale)->delete();
            Ribbon::whereIn('id', $stale)->delete();
        }

        $this->grantAccess($category, $module, $ribbonIds);

        $this->command?->info('Project Management module registered under Operations & Support.');
    }

    private function seedPermissions(): void
    {
        foreach (self::permissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Grant the sets to roles that already exist. Roles are not created here — role design is
        // the tenant's, and inventing one would put users in a group nobody asked for.
        $builtIn = RoleCatalog::builtIn();

        // Platform roles that map onto a PM built-in get that role's permission set, so the two
        // systems agree by construction rather than by someone remembering to update both.
        $grants = [
            'administrator'   => RoleCatalog::ALL,
            'super admin'     => RoleCatalog::ALL,
            'project manager' => $builtIn['project_manager']['permissions'],
            'site supervisor' => $builtIn['team_member']['permissions'],
        ];

        foreach ($grants as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permissions);
        }
    }

    /**
     * Grant module access to everyone who already reaches another Operations & Support module, so
     * the new module appears for the people who work in that area. Falls back to all users on an
     * install where nothing in the category has been granted yet.
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

        // Access rows FK to users; an orphaned id from a since-deleted user would fail the insert.
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
