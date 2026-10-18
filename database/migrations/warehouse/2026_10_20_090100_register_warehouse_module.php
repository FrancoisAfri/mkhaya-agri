<?php

use Database\Seeders\WarehouseModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registers Warehouse Management & Intelligence: module, ribbons and permissions (via the
 * idempotent seeder) and the sellable catalogue entry. Both rows are needed -- one makes the
 * module navigable, the other purchasable; the billing row sells it, it does not grant it.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new WarehouseModuleSeeder)->run();

        DB::table('billing_modules')->updateOrInsert(
            ['code' => 'warehouse_management'],
            [
                'name' => 'Warehouse Management & Intelligence',
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
        $moduleId = DB::table('security_modules')->where('code_name', 'warehouse_management')->value('id');

        if ($moduleId) {
            $ribbonIds = DB::table('security_module_ribbons')->where('module_id', $moduleId)->pluck('id');
            DB::table('security_ribbons_access')->whereIn('ribbon_id', $ribbonIds)->delete();
            DB::table('security_module_ribbons')->where('module_id', $moduleId)->delete();
            DB::table('security_module_access')->where('module_id', $moduleId)->delete();
            DB::table('security_modules')->where('id', $moduleId)->delete();
        }

        DB::table('billing_modules')->where('code', 'warehouse_management')->delete();
    }
};
