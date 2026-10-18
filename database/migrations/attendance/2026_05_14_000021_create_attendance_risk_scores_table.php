<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->decimal('score', 5, 2)->default(0)->comment('0-100 composite risk score');
            $table->string('risk_level', 20)->default('low')->comment('low,medium,high,critical');
            $table->dateTime('calculated_at');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);
            $table->unsignedSmallInteger('missed_clockout_count')->default(0);
            $table->unsignedSmallInteger('anomaly_count')->default(0);
            $table->unsignedSmallInteger('overtime_excessive_days')->default(0);
            $table->decimal('attendance_rate', 5, 2)->default(100)->comment('Percentage of expected days present');
            $table->json('score_breakdown')->nullable()->comment('Per-factor score contributions');
            $table->text('ai_summary')->nullable()->comment('Phase 4: AI narrative summary');
            $table->timestamps();

            $table->index(['employee_id', 'calculated_at']);
            $table->index(['risk_level', 'calculated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_risk_scores');
    }
};
