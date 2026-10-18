<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $module = DB::table('security_modules')
            ->where('code_name', 'boarding')
            ->first();

        if (! $module) {
            return;
        }

        $exists = DB::table('security_module_ribbons')
            ->where('module_id', $module->id)
            ->where('ribbon_path', 'onboarding/templates')
            ->exists();

        if ($exists) {
            return;
        }

        $nextOrder = (int) DB::table('security_module_ribbons')
            ->where('module_id', $module->id)
            ->max('sort_order') + 1;

        DB::table('security_module_ribbons')->insert([
            'uuid'         => (string) Str::uuid(),
            'module_id'    => $module->id,
            'ribbon_name'  => 'Onboarding Templates',
            'ribbon_path'  => 'onboarding/templates',
            'font_awesome' => 'layer-group',
            'description'  => 'Manage reusable onboarding plan templates',
            'sort_order'   => $nextOrder,
            'access_level' => 5,
            'active'       => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('security_module_ribbons')
            ->where('ribbon_path', 'onboarding/templates')
            ->delete();
    }
};
