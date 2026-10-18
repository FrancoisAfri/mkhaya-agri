<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ensures the Accounting (GL) module + its ribbons exist under Finance and are
 * granted to finance users on every environment — runs automatically on deploy
 * (php artisan migrate), so the menu is restored without a manual seeder step.
 * Delegates to the idempotent AccountingModuleSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\AccountingModuleSeeder())->run();
    }

    public function down(): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'accounting')->value('id');
        if ($moduleId) {
            $ribbonIds = DB::table('security_module_ribbons')->where('module_id', $moduleId)->pluck('id');
            DB::table('security_ribbons_access')->whereIn('ribbon_id', $ribbonIds)->delete();
            DB::table('security_module_ribbons')->where('module_id', $moduleId)->delete();
            DB::table('security_module_access')->where('module_id', $moduleId)->delete();
            DB::table('security_modules')->where('id', $moduleId)->delete();
        }
    }
};
