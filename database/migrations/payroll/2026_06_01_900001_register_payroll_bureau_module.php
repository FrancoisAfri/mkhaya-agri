<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registers the Payroll Bureau capability as an activatable security module.
 * Off by default: a company running its own payroll never sees bureau features;
 * an admin activates it only when a payroll bureau buys the system.
 */
return new class extends Migration
{
    public function up(): void
    {
       $payrollCategory = DB::table('security_categories')
            ->where('name', 'Human Resources')
            ->value('id');

        if (!$payrollCategory) {
            $payrollCategory = DB::table('security_categories')->insertGetId([
                'name' => 'Human Resources',
            ]);
        }

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'payroll_bureau'],
            [
                'uuid'         => (string) Str::uuid(),
                'category_id'  => $payrollCategory,
                'name'         => 'Payroll Bureau',
                'path'         => '/payroll/bureau',
                'active'       => 0,
                'font_awesome' => 'fa-building',
                'glyph_icon'   => null,
                'updated_at'   => now(),
                'created_at'   => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('security_modules')->where('code_name', 'payroll_bureau')->delete();
    }
};
