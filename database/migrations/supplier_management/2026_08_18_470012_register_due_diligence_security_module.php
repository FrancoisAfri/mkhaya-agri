<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Migrations\Migration;

/**
 * The add-on needs a `security_modules` row, not just a billing one.
 *
 * `module.active` asks two questions in order: does this module exist and is it switched on for the
 * installation (`security_modules`), and is this tenant entitled to it (`CompanyEntitlements`). The
 * earlier migration answered only the second — so every due-diligence screen redirected to the
 * dashboard on every installation, for every user, however entitled.
 *
 * Nothing in the test suite could see it: the feature tests call routes directly with the module
 * activated by a fixture that writes exactly this row. It took driving the real application in a
 * browser to find, which is the argument for doing that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('security_modules')->where('code_name', 'supplier_due_diligence')->exists()) {
            return;
        }

        // Same category as the module it extends, so it sits beside Supplier Management rather than
        // arriving at the bottom of the list under whatever category happens to be first.
        $categoryId = DB::table('security_modules')
            ->where('code_name', 'supplier_management')
            ->value('category_id')
            ?? DB::table('security_categories')->min('id');

        DB::table('security_modules')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'Supplier Intelligence & Due Diligence',
            'code_name' => 'supplier_due_diligence',
            'path' => '/suppliers/monitoring',
            'active' => 1,
            'category_id' => $categoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('security_modules')->where('code_name', 'supplier_due_diligence')->delete();
    }
};
