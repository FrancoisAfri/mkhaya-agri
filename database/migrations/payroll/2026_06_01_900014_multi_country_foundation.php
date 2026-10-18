<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-country foundation: the SARS tax-year table becomes country-aware
 * (keyed by country + year), and a bureau company carries its country +
 * currency. Existing rows default to South Africa (ZA). Seeds Namibia (NA) as
 * the second jurisdiction to prove the architecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sa_tax_years', function (Blueprint $table) {
            $table->string('country', 2)->default('ZA')->after('id');
            $table->dropUnique(['tax_year']);
            $table->unique(['country', 'tax_year']);
        });

        Schema::table('bureau_companies', function (Blueprint $table) {
            $table->string('country', 2)->default('ZA')->after('pay_frequency');
            $table->string('currency', 3)->default('ZAR')->after('country');
        });

        // ── Namibia (NA) 2026 — annual PAYE brackets (N$); no rebates/medical credits ──
        $brackets = [
            [0,       0.00, 0],
            [100000,  0.18, 0],
            [150000,  0.25, 9000],
            [350000,  0.28, 59000],
            [550000,  0.30, 115000],
            [850000,  0.32, 205000],
            [1550000, 0.37, 429000],
        ];

        DB::table('sa_tax_years')->insert([
            'country'                        => 'NA',
            'tax_year'                       => 2026,
            'starts_on'                      => '2025-03-01',
            'ends_on'                        => '2026-02-28',
            'brackets'                       => json_encode($brackets),
            'primary_rebate'                 => 0,
            'secondary_rebate'               => 0,
            'tertiary_rebate'                => 0,
            'threshold_under_65'             => 100000,
            'threshold_65_to_74'             => 100000,
            'threshold_75_plus'              => 100000,
            'medical_credit_main'            => 0,
            'medical_credit_first_dependant' => 0,
            'medical_credit_additional'      => 0,
            'uif_rate'                        => 0.009,   // Social Security Commission
            'uif_monthly_ceiling'            => 11000,    // max remuneration for SSC
            'sdl_rate'                        => 0.01,     // VET levy
            'sdl_annual_threshold'           => 1000000,
            'eti'                            => null,
            'created_at'                     => now(),
            'updated_at'                     => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('sa_tax_years')->where('country', 'NA')->delete();
        Schema::table('bureau_companies', fn (Blueprint $t) => $t->dropColumn(['country', 'currency']));
        Schema::table('sa_tax_years', function (Blueprint $table) {
            $table->dropUnique(['country', 'tax_year']);
            $table->unique(['tax_year']);
            $table->dropColumn('country');
        });
    }
};
