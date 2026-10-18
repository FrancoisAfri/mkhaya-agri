<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Employment Tax Incentive on an in-house payslip.
 *
 * The in-house engine did not calculate it at all — `Emp201Service` carried a literal
 * `$eti = 0.0; // normal engine does not track ETI` and the payslip had nowhere to put one. So every
 * employer running their own payroll on this product paid SARS the full PAYE and simply never
 * claimed an incentive they were entitled to. Nothing failed; the money was left on the table.
 *
 * Stored beside PAYE and UIF but deliberately outside `total_deductions`: the incentive is the
 * employer's, not the employee's. It reduces what the employer owes SARS on the EMP201 and must
 * never touch net pay — an employee whose payslip went up because their employer qualified for an
 * incentive would be a serious error, not a bonus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('eti', 12, 2)->default(0)->after('uif');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            // The figure the EMP201 offsets against, totalled per run like the others.
            $table->decimal('total_eti', 16, 2)->default(0)->after('total_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', fn (Blueprint $table) => $table->dropColumn('eti'));
        Schema::table('payroll_runs', fn (Blueprint $table) => $table->dropColumn('total_eti'));
    }
};
