<?php

use Database\Seeders\ProcurementGovernanceModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers Procurement Governance on existing installs: the module, its ribbons, the §10
 * permission set and each tenant's starter configuration (via the idempotent seeder), plus its
 * entry in the sellable module catalogue.
 *
 * The billing entry makes the module purchasable; it does not grant it. A SaaS tenant reaches it
 * once their plan or à-la-carte composition includes 'procurement_governance', and a self-hosted
 * install once the signed licence does — the same gate as every other module, enforced by
 * EnsureModuleIsActive. §12's finer question, which capabilities *inside* the module are on, is
 * answered per tenant from procgov_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ProcurementGovernanceModuleSeeder)->run();

        DB::table('billing_modules')->updateOrInsert(
            ['code' => 'procurement_governance'],
            [
                'name' => 'Procurement Governance',
                'category' => 'Supply Chain',
                'price_monthly' => 550,
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
        $moduleId = DB::table('security_modules')->where('code_name', 'procurement_governance')->value('id');

        if ($moduleId) {
            $ribbonIds = DB::table('security_module_ribbons')->where('module_id', $moduleId)->pluck('id');
            DB::table('security_ribbons_access')->whereIn('ribbon_id', $ribbonIds)->delete();
            DB::table('security_module_ribbons')->where('module_id', $moduleId)->delete();
            DB::table('security_module_access')->where('module_id', $moduleId)->delete();
            DB::table('security_modules')->where('id', $moduleId)->delete();
        }

        DB::table('billing_modules')->where('code', 'procurement_governance')->delete();

        // Permissions are left in place: a role may have been granted them by hand, and removing a
        // permission silently strips it from every role that references it.
    }
};
