<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let users pin a custom metric (KPI) to the main CRM dashboard so it shows
 * as a live card alongside the built-in figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_kpis') && ! Schema::hasColumn('crm_kpis', 'pin_to_dashboard')) {
            Schema::table('crm_kpis', function (Blueprint $t) {
                $t->boolean('pin_to_dashboard')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_kpis') && Schema::hasColumn('crm_kpis', 'pin_to_dashboard')) {
            Schema::table('crm_kpis', fn (Blueprint $t) => $t->dropColumn('pin_to_dashboard'));
        }
    }
};
