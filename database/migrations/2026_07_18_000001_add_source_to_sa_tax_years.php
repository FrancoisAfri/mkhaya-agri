<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks where each SARS tax-year row came from and whether it has been
 * confirmed by a payroll administrator. The mid-February rollover cron
 * carries the previous year forward as a PROVISIONAL row (confirmed_at = null)
 * so the new brackets can be reviewed against the budget speech before use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sa_tax_years', function (Blueprint $table) {
            if (! Schema::hasColumn('sa_tax_years', 'source')) {
                $table->string('source', 32)->default('manual')->after('country');
            }
            if (! Schema::hasColumn('sa_tax_years', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('source');
            }
        });

        // Existing rows were hand-seeded and are trusted — mark them confirmed.
        \Illuminate\Support\Facades\DB::table('sa_tax_years')
            ->whereNull('confirmed_at')
            ->update(['confirmed_at' => now(), 'source' => 'seed']);
    }

    public function down(): void
    {
        Schema::table('sa_tax_years', function (Blueprint $table) {
            $table->dropColumn(['source', 'confirmed_at']);
        });
    }
};
