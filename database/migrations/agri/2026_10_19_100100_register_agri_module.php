<?php

use Database\Seeders\AgriModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers Mkhaya Agri on existing installs: the module, ribbons and permissions (via the
 * idempotent seeder) and its entry in the sellable module catalogue. Both rows are needed — the
 * security module makes it navigable, the billing module makes it purchasable.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new AgriModuleSeeder)->run();

        DB::table('billing_modules')->updateOrInsert(
            ['code' => 'agri'],
            [
                'name' => 'Farm Management',
                'category' => 'Farm Operations',
                'price_monthly' => 0,
                'is_sellable' => true,
                'is_active' => true,
                'sort' => (int) DB::table('billing_modules')->max('sort') + 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'agri')->value('id');

        if ($moduleId) {
            $ribbonIds = DB::table('security_module_ribbons')->where('module_id', $moduleId)->pluck('id');
            DB::table('security_ribbons_access')->whereIn('ribbon_id', $ribbonIds)->delete();
            DB::table('security_module_ribbons')->where('module_id', $moduleId)->delete();
            DB::table('security_module_access')->where('module_id', $moduleId)->delete();
            DB::table('security_modules')->where('id', $moduleId)->delete();
        }

        DB::table('billing_modules')->where('code', 'agri')->delete();
    }
};
