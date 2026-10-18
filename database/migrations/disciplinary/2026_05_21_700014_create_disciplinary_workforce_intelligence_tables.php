<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cached behavioral profile per employee (computed from attendance, performance, payroll signals)
        Schema::create('disciplinary_behavior_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');

            // Attendance signals
            $table->integer('late_arrivals')->default(0);
            $table->integer('unexcused_absences')->default(0);
            $table->integer('early_departures')->default(0);
            $table->decimal('attendance_score', 5, 2)->default(100); // 0-100

            // Performance signals
            $table->decimal('performance_rating')->nullable(); // from pa_performance_snapshots
            $table->integer('missed_kpis')->default(0);
            $table->decimal('performance_score', 5, 2)->default(100);

            // Disciplinary signals
            $table->integer('active_warnings')->default(0);
            $table->integer('total_cases_ytd')->default(0);
            $table->integer('repeated_offences')->default(0);
            $table->decimal('disciplinary_score', 5, 2)->default(100);

            // Compliance signals
            $table->integer('overdue_training_items')->default(0);
            $table->decimal('compliance_score', 5, 2)->default(100);

            // Overall
            $table->decimal('overall_risk_score', 5, 2)->default(0); // 0=low risk, 100=critical
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->json('risk_factors')->nullable();
            $table->text('ai_narrative')->nullable();

            $table->boolean('is_ai_generated')->default(false);
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'period_start', 'period_end']);
            $table->index(['risk_level', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_behavior_profiles');
    }
};
