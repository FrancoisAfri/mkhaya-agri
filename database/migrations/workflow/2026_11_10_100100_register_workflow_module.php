<?php

use Database\Seeders\WorkflowModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers Workflow & Automation: module, ribbons, permissions and role templates (via the
 * idempotent seeder) and the sellable catalogue entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new WorkflowModuleSeeder)->run();

        DB::table('billing_modules')->updateOrInsert(
            ['code' => 'workflow'],
            [
                'name' => 'Workflow & Automation', 'category' => 'Operations & Support', 'price_monthly' => 750,
                'is_sellable' => true, 'is_active' => true,
                'sort' => (int) DB::table('billing_modules')->max('sort') + 1,
                'updated_at' => now(), 'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'workflow')->value('id');
        if ($moduleId) {
            $ribbonIds = DB::table('security_module_ribbons')->where('module_id', $moduleId)->pluck('id');
            DB::table('security_ribbons_access')->whereIn('ribbon_id', $ribbonIds)->delete();
            DB::table('security_module_ribbons')->where('module_id', $moduleId)->delete();
            DB::table('security_module_access')->where('module_id', $moduleId)->delete();
            DB::table('security_modules')->where('id', $moduleId)->delete();
        }
        DB::table('billing_modules')->where('code', 'workflow')->delete();
    }
};
