<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_earnings_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->string('line_type', 30); // basic_salary|overtime|bonus|allowance|commission|paye|uif|pension|other_deduction
            $table->string('description', 200);
            $table->decimal('amount', 14, 2);
            $table->decimal('quantity', 8, 4)->default(1); // e.g. overtime hours
            $table->timestamps();

            $table->index('payslip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_earnings_lines');
    }
};
