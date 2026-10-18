<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $module = DB::table('security_modules')
            ->where('code_name', 'performance_appraisal')
            ->first();

        if (! $module) {
            return;
        }

        $exists = DB::table('security_module_ribbons')
            ->where('module_id', $module->id)
            ->where('ribbon_path', 'performance/my-dashboard')
            ->exists();

        if ($exists) {
            return;
        }

        // Insert at position 2 (after Dashboard) — shift existing ribbons with sort_order >= 2
        DB::table('security_module_ribbons')
            ->where('module_id', $module->id)
            ->where('sort_order', '>=', 2)
            ->increment('sort_order');

        DB::table('security_module_ribbons')->insert([
            'uuid'         => (string) Str::uuid(),
            'module_id'    => $module->id,
            'ribbon_name'  => 'My Dashboard',
            'ribbon_path'  => 'performance/my-dashboard',
            'font_awesome' => 'user-circle',
            'description'  => 'Personal performance appraisal summary',
            'sort_order'   => 2,
            'access_level' => 1,
            'active'       => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down(): void
    {
        $module = DB::table('security_modules')
            ->where('code_name', 'performance_appraisal')
            ->first();

        if (! $module) {
            return;
        }

        DB::table('security_module_ribbons')
            ->where('module_id', $module->id)
            ->where('ribbon_path', 'performance/my-dashboard')
            ->delete();

        // Restore sort_order for ribbons that were shifted
        DB::table('security_module_ribbons')
            ->where('module_id', $module->id)
            ->where('sort_order', '>=', 3)
            ->decrement('sort_order');
    }
};
