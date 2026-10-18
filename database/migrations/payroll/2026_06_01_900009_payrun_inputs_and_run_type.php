<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Once-off pay inputs (13th cheque/bonus, retro/back-pay, leave pay-out,
 * severance, garnishee) attached to a specific run + employee, plus a run_type
 * so off-cycle/termination runs are distinguishable from the regular cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bureau_pay_runs', fn (Blueprint $t) => $t->string('run_type')->default('regular')->after('frequency'));

        Schema::create('bureau_payrun_inputs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bureau_pay_run_id')->constrained('bureau_pay_runs')->cascadeOnDelete();
            $table->foreignId('bureau_employee_id')->constrained('bureau_employees')->cascadeOnDelete();
            $table->string('kind');            // bonus | retro | leave_payout | severance | garnishee | other_earning | other_deduction
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->string('sars_source_code')->nullable();
            $table->timestamps();

            $table->index(['bureau_pay_run_id', 'bureau_employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bureau_payrun_inputs');
        Schema::table('bureau_pay_runs', fn (Blueprint $t) => $t->dropColumn('run_type'));
    }
};
