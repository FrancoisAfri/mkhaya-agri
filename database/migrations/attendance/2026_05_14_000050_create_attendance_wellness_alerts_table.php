<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_wellness_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->string('alert_type', 50)->comment('stress_indicator,burnout_signal,withdrawal,overtime_overload,trend_decline,department_risk');
            $table->string('severity', 20)->default('medium')->comment('low,medium,high,critical');
            $table->string('severity_color', 20)->default('warning');
            $table->date('period_start');
            $table->date('period_end');
            $table->text('description');
            $table->json('evidence')->nullable();
            $table->text('ai_insight')->nullable();
            $table->string('status', 20)->default('open')->comment('open,acknowledged,resolved,dismissed');
            $table->text('manager_notes')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['alert_type', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_wellness_alerts');
    }
};
