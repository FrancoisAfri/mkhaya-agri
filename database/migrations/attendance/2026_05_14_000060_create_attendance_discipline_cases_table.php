<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_discipline_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->string('case_number', 30)->unique();
            $table->string('case_type', 40)->comment('verbal_warning,written_warning,final_warning,suspension,dismissal');
            $table->string('status', 30)->default('pending')->comment('pending,in_progress,resolved,closed,dismissed');
            $table->string('severity', 20)->default('medium')->comment('low,medium,high,critical');
            $table->string('severity_color', 20)->default('warning');
            $table->date('period_start');
            $table->date('period_end');
            $table->text('summary');
            $table->json('violations')->nullable()->comment('list of attendance violations');
            $table->text('ai_recommendation')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status']);
            $table->index('case_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_discipline_cases');
    }
};
