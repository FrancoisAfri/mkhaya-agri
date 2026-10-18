<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('report_key'); // payslips, gross_breakdown, deductions, net_pay, overtime, headcount, variance, tax, anomalies, corrections, leakage
            $table->enum('cadence', ['daily', 'weekly', 'monthly'])->default('monthly');
            $table->string('run_time', 5)->default('07:00'); // HH:MM
            $table->unsignedTinyInteger('day_of_week')->nullable();  // 1=Mon…7=Sun (weekly)
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1-28 (monthly)
            $table->enum('format', ['pdf', 'excel', 'both'])->default('pdf');
            $table->text('recipients'); // comma-separated emails
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_scheduled_reports');
    }
};
