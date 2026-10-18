<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a pay line came from.
 *
 * The overtime calculator needs to replace its own lines when a timesheet is corrected and the
 * button is pressed again — otherwise a second press doubles somebody's overtime. It must not touch
 * lines a person typed in, which are not its to remove. Without a way to tell the two apart it could
 * only do one or the other, and both choices are wrong.
 *
 * Defaults to 'manual', which is what every existing line is: somebody entered it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_earnings_lines', function (Blueprint $table) {
            $table->string('source', 30)->default('manual')->after('quantity');
            $table->index(['payslip_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_earnings_lines', function (Blueprint $table) {
            $table->dropIndex(['payslip_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
