<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Modules\Finance\Tax\Support\TaxHubs;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Registers Tax Management & Compliance under Finance: permissions, starter role templates, the
 * module row and its ribbons (from TaxHubs). Idempotent; later phases re-run it.
 *
 * Module access is navigation only. What a person may do is the tax.* permissions, and only the
 * tenant administrator role receives them by default — a system administrator is not thereby a tax
 * approver (§16).
 */
class TaxModuleSeeder extends Seeder
{
    private const LEVEL = 5;

    private const MODULE_CODE = 'tax_management';

    public function run(): void
    {
        foreach (TaxPermissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::where('name', 'administrator')->where('guard_name', 'web')->first()?->givePermissionTo(TaxPermissions::all());

        foreach (TaxPermissions::roleTemplates() as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            // A template somebody already changed is theirs; only a new role gets the starter set.
            if ($role->wasRecentlyCreated) {
                $role->givePermissionTo($permissions);
            }
        }

        $category = Category::firstOrCreate(['name' => 'Finance'], ['active' => 1, 'description' => 'Finance', 'icon' => 'currency-dollar']);

        $module = Modules::firstOrNew(['code_name' => self::MODULE_CODE]);
        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }
        $module->fill([
            'active' => 1, 'category_id' => $category->id, 'name' => 'Tax Management & Compliance',
            'path' => '/finance/tax', 'font_awesome' => 'receipt',
        ])->save();

        $ribbons = TaxHubs::navRibbons();
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

        // Navigation for whoever already reaches Finance modules.
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
