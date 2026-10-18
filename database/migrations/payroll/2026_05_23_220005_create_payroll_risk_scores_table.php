<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('payslip_id')->nullable();
            $table->unsignedBigInteger('employee_id');
            $table->tinyInteger('score')->default(0);
            $table->enum('level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->json('factors')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_risk_scores');
    }
};
