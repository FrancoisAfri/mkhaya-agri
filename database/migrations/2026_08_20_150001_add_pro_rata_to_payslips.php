<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a payslip is for less than a full period.
 *
 * The run paid a full period whatever the start or leaving date, so a joiner on the 20th received
 * the whole month. Reducing the amount is only half the fix — an employee who is paid less than
 * they expected needs the payslip to say why, and a payroll officer needs to be able to check the
 * arithmetic without recomputing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->json('pro_rata')->nullable()->after('basic_salary');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn('pro_rata'));
    }
};
