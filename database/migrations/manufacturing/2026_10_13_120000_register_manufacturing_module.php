<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers Manufacturing & Production on existing installs: the module, its ribbons and
 * permissions (via the idempotent seeder) and its entry in the sellable module catalogue.
 *
 * Both a security_modules row and a billing_modules row are required. The first makes the module
 * navigable; the second makes it purchasable. A module with only the first is reachable by every
 * tenant for free, and one with only the second is sold and then 404s.
 *
 * The billing entry makes it purchasable; it does not grant it. A SaaS tenant reaches it once
 * their plan includes 'manufacturing', and a self-hosted install once the licence does -- the same
 * EnsureModuleIsActive gate as every other module.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ManufacturingModuleSeeder)->run();

        DB::table('billing_modules')->updateOrInsert(
            ['code' => 'manufacturing'],
            [
                'name' => 'Manufacturing & Production',
                'category' => 'Operations',
                'price_monthly' => 650,
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
        $moduleId = DB::table('security_modules')->where('code_name', 'manufacturing')->value('id');

        if ($moduleId) {
            $ribbonIds = DB::table('security_module_ribbons')->where('module_id', $moduleId)->pluck('id');
            DB::table('security_ribbons_access')->whereIn('ribbon_id', $ribbonIds)->delete();
            DB::table('security_module_ribbons')->where('module_id', $moduleId)->delete();
            DB::table('security_module_access')->where('module_id', $moduleId)->delete();
            DB::table('security_modules')->where('id', $moduleId)->delete();
        }

        DB::table('billing_modules')->where('code', 'manufacturing')->delete();

        // Permissions stay: a role may have been granted them by hand, and removing a permission
        // silently strips it from every role that references it.
    }
};
