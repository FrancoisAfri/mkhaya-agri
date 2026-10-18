<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_wellness_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('wellness_score')->default(100)->comment('0-100, higher = healthier');
            $table->string('burnout_risk', 20)->default('low')->comment('low,medium,high,critical');
            $table->string('fatigue_level', 20)->default('low')->comment('low,medium,high');
            $table->decimal('total_overtime_hours', 7, 2)->default(0);
            $table->unsignedSmallInteger('consecutive_days_worked')->default(0);
            $table->decimal('rest_deficit_hours', 7, 2)->default(0);
            $table->unsignedSmallInteger('late_arrivals_count')->default(0);
            $table->decimal('absenteeism_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('weekend_work_count')->default(0);
            $table->unsignedSmallInteger('shift_changes_count')->default(0);
            $table->json('score_breakdown')->nullable();
            $table->text('ai_summary')->nullable();
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();

            $table->index(['employee_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_wellness_scores');
    }
};
