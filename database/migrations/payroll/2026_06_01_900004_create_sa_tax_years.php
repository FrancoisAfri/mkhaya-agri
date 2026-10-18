<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SARS statutory parameters per year of assessment. Data-driven so a new tax
 * year is a row, not a code change. Seeds the 2025 and 2026 years (SARS kept the
 * brackets, rebates and medical credits unchanged between them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sa_tax_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('tax_year')->unique();   // year of assessment, e.g. 2026
            $table->date('starts_on');
            $table->date('ends_on');
            $table->json('brackets');                              // [[lower, rate, base], ...] annual
            $table->decimal('primary_rebate', 12, 2);
            $table->decimal('secondary_rebate', 12, 2);           // 65+
            $table->decimal('tertiary_rebate', 12, 2);            // 75+
            $table->decimal('threshold_under_65', 12, 2);
            $table->decimal('threshold_65_to_74', 12, 2);
            $table->decimal('threshold_75_plus', 12, 2);
            $table->decimal('medical_credit_main', 8, 2);         // per month
            $table->decimal('medical_credit_first_dependant', 8, 2);
            $table->decimal('medical_credit_additional', 8, 2);
            $table->decimal('uif_rate', 6, 4);                    // 0.01
            $table->decimal('uif_monthly_ceiling', 12, 2);        // remuneration cap, e.g. 17712
            $table->decimal('sdl_rate', 6, 4);                    // 0.01
            $table->decimal('sdl_annual_threshold', 14, 2);       // payroll exemption, e.g. 500000
            $table->json('eti')->nullable();                      // ETI band config
            $table->timestamps();
        });

        // 2025 & 2026 brackets (annual): [lower bound, marginal rate, cumulative tax at lower bound]
        $brackets = [
            [0,       0.18, 0],
            [237100,  0.26, 42678],
            [370500,  0.31, 77362],
            [512800,  0.36, 121475],
            [673000,  0.39, 179147],
            [857900,  0.41, 251258],
            [1817000, 0.45, 644489],
        ];
        // 2022 SARS ETI table (still the published formula); cap on benefit at R6,500.
        $eti = [
            'qualify_max' => 7500,   // monthly remuneration must be below this to qualify
            'benefit_cap' => 6500,
            'p1' => ['pct' => 0.50, 'flat' => 1000, 'taper' => 0.50], // first 12 months
            'p2' => ['pct' => 0.25, 'flat' => 500,  'taper' => 0.25], // second 12 months
            'lower' => 2000, 'upper' => 4500,
        ];

        $common = [
            'brackets'                       => json_encode($brackets),
            'primary_rebate'                 => 17235,
            'secondary_rebate'               => 9444,
            'tertiary_rebate'                => 3145,
            'threshold_under_65'             => 95750,
            'threshold_65_to_74'             => 148217,
            'threshold_75_plus'              => 165689,
            'medical_credit_main'            => 364,
            'medical_credit_first_dependant' => 364,
            'medical_credit_additional'      => 246,
            'uif_rate'                       => 0.01,
            'uif_monthly_ceiling'            => 17712,
            'sdl_rate'                        => 0.01,
            'sdl_annual_threshold'           => 500000,
            'eti'                            => json_encode($eti),
            'created_at'                     => now(),
            'updated_at'                     => now(),
        ];

        DB::table('sa_tax_years')->insert([
            $common + ['tax_year' => 2025, 'starts_on' => '2024-03-01', 'ends_on' => '2025-02-28'],
            $common + ['tax_year' => 2026, 'starts_on' => '2025-03-01', 'ends_on' => '2026-02-28'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sa_tax_years');
    }
};
