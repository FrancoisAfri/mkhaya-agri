<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overtime hours a bureau officer captures, rather than an amount they work out.
 *
 * A bureau's clients do not clock into this system — there is no attendance to read, which is why
 * the in-house overtime calculator has nothing to work from here. But asking an officer to compute
 * the amount themselves is asking them to do the arithmetic the BCEA specifies, per employee, by
 * hand: hourly rate from the package, times 1.5 or 2, times the hours. That is where the errors are.
 *
 * So the officer captures *hours* and the category, and the engine applies the multiplier against
 * that employee's own rate. Two people on different salaries entering the same four hours get
 * different amounts, correctly, without anybody reaching for a calculator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bureau_payrun_inputs', function (Blueprint $table) {
            // Null for every existing input, which are all amounts. Only the overtime kinds use it.
            $table->decimal('hours', 8, 2)->nullable()->after('amount');

            // The rate the amount was worked out at, kept so a payslip can show its arithmetic and
            // a query later can explain how a figure was reached.
            $table->decimal('rate_applied', 10, 2)->nullable()->after('hours');
            $table->decimal('multiplier', 4, 2)->nullable()->after('rate_applied');
        });
    }

    public function down(): void
    {
        Schema::table('bureau_payrun_inputs', fn (Blueprint $t) => $t->dropColumn(['hours', 'rate_applied', 'multiplier']));
    }
};
