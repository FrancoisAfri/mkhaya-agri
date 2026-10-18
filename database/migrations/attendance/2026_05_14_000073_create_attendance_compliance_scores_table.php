<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_compliance_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('total_score')->default(0);      // 0-100
            $table->unsignedTinyInteger('lateness_score')->default(25);  // 0-25
            $table->unsignedTinyInteger('overtime_score')->default(25);  // 0-25
            $table->unsignedTinyInteger('attendance_score')->default(25);// 0-25
            $table->unsignedTinyInteger('adherence_score')->default(25); // 0-25
            $table->unsignedSmallInteger('violations_count')->default(0);
            $table->unsignedSmallInteger('exceptions_count')->default(0);
            $table->text('ai_summary')->nullable();
            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();

            $table->index(['employee_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_compliance_scores');
    }
};
