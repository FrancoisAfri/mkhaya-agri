<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_department_wellness_scores', function (Blueprint $table) {
            $table->id();
            $table->string('department', 100);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('wellness_score')->default(100)->comment('0-100 aggregate');
            $table->string('risk_level', 20)->default('low');
            $table->string('risk_color', 20)->default('success');
            $table->unsignedSmallInteger('headcount')->default(0);
            $table->unsignedSmallInteger('high_risk_count')->default(0);
            $table->unsignedSmallInteger('open_alerts_count')->default(0);
            $table->decimal('avg_absenteeism_rate', 5, 2)->default(0);
            $table->decimal('avg_overtime_hours', 7, 2)->default(0);
            $table->json('top_issues')->nullable();
            $table->text('ai_summary')->nullable();
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['department', 'period_start', 'period_end']);
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_department_wellness_scores');
    }
};
