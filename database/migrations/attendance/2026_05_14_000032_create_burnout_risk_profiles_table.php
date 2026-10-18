<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('burnout_risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('overall_risk', 20)->default('low')->comment('low,medium,high,critical');
            $table->string('risk_color', 20)->default('success');
            $table->unsignedTinyInteger('overtime_overload_score')->default(0)->comment('0-100');
            $table->unsignedTinyInteger('consecutive_work_score')->default(0)->comment('0-100');
            $table->unsignedTinyInteger('absence_pattern_score')->default(0)->comment('0-100');
            $table->unsignedTinyInteger('late_arrival_score')->default(0)->comment('0-100');
            $table->unsignedTinyInteger('shift_instability_score')->default(0)->comment('0-100');
            $table->json('risk_factors')->nullable();
            $table->json('recommendations')->nullable();
            $table->text('ai_narrative')->nullable();
            $table->timestamp('assessed_at')->useCurrent();
            $table->timestamps();

            $table->index(['employee_id', 'period_start']);
            $table->index('overall_risk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('burnout_risk_profiles');
    }
};
