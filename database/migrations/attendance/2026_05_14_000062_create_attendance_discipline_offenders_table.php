<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_discipline_offenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->unsignedSmallInteger('total_violations')->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedSmallInteger('absent_count')->default(0);
            $table->unsignedSmallInteger('no_show_count')->default(0);
            $table->unsignedSmallInteger('early_departure_count')->default(0);
            $table->unsignedSmallInteger('prior_warnings')->default(0);
            $table->string('offender_level', 20)->default('minor')->comment('minor,moderate,serious,repeat');
            $table->string('offender_color', 20)->default('warning');
            $table->date('first_offence_date')->nullable();
            $table->date('last_offence_date')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->json('violation_breakdown')->nullable();
            $table->text('ai_risk_assessment')->nullable();
            $table->timestamp('assessed_at')->useCurrent();
            $table->timestamps();

            $table->index(['employee_id', 'period_start']);
            $table->index('offender_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_discipline_offenders');
    }
};
