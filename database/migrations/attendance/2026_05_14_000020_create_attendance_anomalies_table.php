<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->string('anomaly_type', 50)
                ->comment('repeated_lateness,unusual_absenteeism,excessive_overtime,manipulation,buddy_clocking,missing_clockout,suspicious_pattern,shift_abuse');
            $table->string('severity', 20)->default('medium')->comment('low,medium,high,critical');
            $table->dateTime('detected_at');
            $table->date('period_start');
            $table->date('period_end');
            $table->text('description');
            $table->json('evidence')->nullable()->comment('Supporting data: counts, dates, clockings');
            $table->string('status', 20)->default('open')->comment('open,acknowledged,resolved,false_positive');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('acknowledged_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->text('ai_explanation')->nullable()->comment('Phase 4: AI context-aware explanation');
            $table->timestamps();

            $table->index(['employee_id', 'detected_at']);
            $table->index(['anomaly_type', 'severity', 'status']);
            $table->index(['status', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_anomalies');
    }
};
