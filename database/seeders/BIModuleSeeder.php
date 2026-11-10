<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Registers Business Intelligence: the sellable module (billing), its menu entry and access.
 *
 * Access is granted at SuperUser level only to the organisation's administrators (anyone with
 * Admin+ on System Settings or Company Settings) — they can design. Everyone else gets BI when an
 * administrator gives it to them in User Access: level 1–3 views the templates, 4–5 designs.
 */
class BIModuleSeeder extends Seeder
{
    public function run(): void
    {
        $category = Category::firstOrCreate(['name' => 'Business Intelligence'], ['active' => 1, 'description' => 'Dashboards and analytics across every module', 'icon' => 'chart-pie']);

        $module = Modules::firstOrNew(['code_name' => 'business_intelligence']);
        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }
        $module->fill(['active' => 1, 'category_id' => $category->id, 'name' => 'Analytics', 'path' => '/bi', 'font_awesome' => 'chart-pie'])->save();

        $ribbon = Ribbon::firstOrNew(['module_id' => $module->id, 'ribbon_name' => 'Dashboards']);
        if (! $ribbon->exists) {
            $ribbon->uuid = (string) Str::uuid();
        }
        $ribbon->fill(['active' => 1, 'sort_order' => 1, 'ribbon_path' => '/bi', 'description' => 'Dashboards', 'font_awesome' => 'chart-pie', 'access_level' => 1])->save();

        $adminModules = DB::table('security_modules')->whereIn('code_name', ['system_settings', 'company_settings'])->pluck('id');
        $admins = ModuleAccess::whereIn('module_id', $adminModules)->where('access_level', '>=', 4)->distinct()->pluck('user_id')
            ->intersect(DB::table('users')->pluck('id'))->values();
        foreach ($admins as $userId) {
            CategoryAccess::firstOrCreate(['category_id' => $category->id, 'user_id' => $userId], ['active' => 1, 'access_level' => 5]);
            ModuleAccess::firstOrCreate(['module_id' => $module->id, 'user_id' => $userId], ['active' => 1, 'access_level' => 5]);
            RibbonAccess::firstOrCreate(['ribbon_id' => $ribbon->id, 'user_id' => $userId], ['active' => 1, 'access_level' => 5]);
        }

        if (Schema::hasTable('billing_modules') && ! DB::table('billing_modules')->where('code', 'business_intelligence')->exists()) {
            DB::table('billing_modules')->insert(['code' => 'business_intelligence', 'name' => 'Business Intelligence (dashboards)', 'category' => 'Platform',
                'price_monthly' => 0, 'is_sellable' => true, 'is_active' => true, 'sort' => 990, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->command?->info("Business Intelligence registered; granted to {$admins->count()} administrator(s).");
    }
}
