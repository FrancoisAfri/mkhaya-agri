<?php

use Database\Seeders\SheqModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers SHEQ on existing installs: the module + ribbons + permissions (via the idempotent
 * seeder) and its entry in the sellable module catalogue.
 *
 * The billing entry makes the module purchasable; it does not grant it. A SaaS tenant reaches it
 * once their plan or à-la-carte composition includes 'sheq', and a self-hosted install once the
 * signed licence does — the same gate as every other module, enforced by EnsureModuleIsActive.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new SheqModuleSeeder)->run();

        DB::table('billing_modules')->updateOrInsert(
            ['code' => 'sheq'],
            [
                'name' => 'SHEQ',
                'category' => 'Operations',
                'price_monthly' => 450,
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
        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId) {
            $ribbonIds = DB::table('security_module_ribbons')->where('module_id', $moduleId)->pluck('id');
            DB::table('security_ribbons_access')->whereIn('ribbon_id', $ribbonIds)->delete();
            DB::table('security_module_ribbons')->where('module_id', $moduleId)->delete();
            DB::table('security_module_access')->where('module_id', $moduleId)->delete();
            DB::table('security_modules')->where('id', $moduleId)->delete();
        }

        DB::table('billing_modules')->where('code', 'sheq')->delete();

        // Permissions are left in place: a role may have been granted them by hand, and removing a
        // permission silently strips it from every role that references it.
    }
};
