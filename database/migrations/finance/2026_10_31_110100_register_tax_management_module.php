<?php

use Database\Seeders\TaxModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers Tax Management & Compliance: module, ribbons, permissions and role templates (via the
 * idempotent seeder) and the sellable catalogue entry. Being sellable is not being enabled: a
 * tenant gets the module when it is switched on for them (§2, "subject to enabled modules").
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TaxModuleSeeder)->run();

        DB::table('billing_modules')->updateOrInsert(
            ['code' => 'tax_management'],
            [
                'name' => 'Tax Management & Compliance', 'category' => 'Finance', 'price_monthly' => 650,
                'is_sellable' => true, 'is_active' => true,
                'sort' => (int) DB::table('billing_modules')->max('sort') + 1,
                'updated_at' => now(), 'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'tax_management')->value('id');
        if ($moduleId) {
            $ribbonIds = DB::table('security_module_ribbons')->where('module_id', $moduleId)->pluck('id');
            DB::table('security_ribbons_access')->whereIn('ribbon_id', $ribbonIds)->delete();
            DB::table('security_module_ribbons')->where('module_id', $moduleId)->delete();
            DB::table('security_module_access')->where('module_id', $moduleId)->delete();
            DB::table('security_modules')->where('id', $moduleId)->delete();
        }
        DB::table('billing_modules')->where('code', 'tax_management')->delete();
    }
};
