<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The labour-law figures, versioned the way the tax figures already are.
 *
 * Everything SARS publishes is read from `sa_tax_years` and versioned by year, which is why a
 * February rate change is data entry. The labour-law numbers were not: the National Minimum Wage,
 * the weekly overtime ceiling and the overtime multiplier sat in PHP constants in
 * PayrollComplianceService. They change every March, and every March somebody would have had to
 * edit code and deploy — on a class that decides whether a payroll is flagged as underpaying.
 *
 * National rather than per-tenant. These are statutes; a company does not get its own minimum wage.
 * A sectoral determination that genuinely differs belongs in a future per-sector row, which is why
 * the sector column is here and nullable rather than absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sa_labour_rates', function (Blueprint $table) {
            $table->id();
            $table->string('country', 2)->default('ZA');

            // Null means the general determination. A named sector overrides it for employers in
            // that sector — farm workers and domestic workers have had their own rates before.
            $table->string('sector', 60)->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->decimal('nmw_hourly', 10, 2);
            $table->decimal('standard_monthly_hours', 8, 2)->default(160);
            $table->unsignedSmallInteger('max_overtime_hours_per_week')->default(10);

            // BCEA §10, §16, §18. Kept as multipliers rather than derived amounts so a sectoral
            // agreement that pays more than the statute can simply raise the number.
            $table->decimal('overtime_multiplier', 4, 2)->default(1.50);
            $table->decimal('sunday_multiplier', 4, 2)->default(2.00);
            $table->decimal('public_holiday_multiplier', 4, 2)->default(2.00);

            // COIDA assesss earnings only up to an annual ceiling, and it moves yearly. Phase 4
            // reads it from here rather than introducing a second place for statutory figures.
            $table->decimal('coida_earnings_ceiling', 14, 2)->nullable();

            $table->string('source')->nullable();
            $table->timestamps();

            $table->unique(['country', 'sector', 'effective_from'], 'sa_labour_rates_unique');
        });

        // The figures that were hard-coded, with the date they actually took effect. The 2025 NMW
        // is the one the constant carried; the 2024 row is here so a payslip reprinted for an old
        // period is measured against the rate that applied then.
        DB::table('sa_labour_rates')->insert([
            [
                'country' => 'ZA', 'sector' => null,
                'effective_from' => '2024-03-01', 'effective_to' => '2025-02-28',
                'nmw_hourly' => 27.58, 'standard_monthly_hours' => 160,
                'max_overtime_hours_per_week' => 10,
                'overtime_multiplier' => 1.50, 'sunday_multiplier' => 2.00, 'public_holiday_multiplier' => 2.00,
                'coida_earnings_ceiling' => 563520.00,
                'source' => 'National Minimum Wage Act determination',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'country' => 'ZA', 'sector' => null,
                'effective_from' => '2025-03-01', 'effective_to' => null,
                'nmw_hourly' => 28.79, 'standard_monthly_hours' => 160,
                'max_overtime_hours_per_week' => 10,
                'overtime_multiplier' => 1.50, 'sunday_multiplier' => 2.00, 'public_holiday_multiplier' => 2.00,
                'coida_earnings_ceiling' => 597328.00,
                'source' => 'National Minimum Wage Act determination',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sa_labour_rates');
    }
};
