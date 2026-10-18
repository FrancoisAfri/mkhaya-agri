<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('case_id')->nullable()->constrained('disciplinary_cases')->nullOnDelete();
            $table->string('action_number')->unique();
            $table->enum('action_type', ['training', 'counseling', 'coaching', 'performance_plan', 'work_shadowing', 'mentoring', 'other']);
            $table->string('title');
            $table->text('description');
            $table->foreignId('assigned_by')->constrained('users');
            $table->date('assigned_date');
            $table->date('due_date');
            $table->date('completed_date')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->integer('progress_percent')->default(0);
            $table->text('progress_notes')->nullable();
            $table->text('completion_evidence')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('ai_recommended')->default(false);
            $table->string('ai_reasoning')->nullable();
            // File evidence
            $table->string('file_uuid')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->longText('file_data')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['due_date', 'status']);
        });

        Schema::create('disciplinary_coaching_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('case_id')->nullable()->constrained('disciplinary_cases')->nullOnDelete();
            $table->foreignId('corrective_action_id')->nullable()->constrained('disciplinary_corrective_actions')->nullOnDelete();
            $table->foreignId('coach_id')->constrained('users');
            $table->enum('session_type', ['coaching', 'counseling', 'mentoring', 'mediation', 'performance_review']);
            $table->timestamp('scheduled_at');
            $table->integer('duration_minutes')->nullable();
            $table->string('venue')->nullable();
            $table->text('agenda')->nullable();
            $table->text('session_notes')->nullable();
            $table->text('outcome')->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'rescheduled', 'no_show'])->default('scheduled');
            $table->timestamp('rescheduled_to')->nullable();
            $table->boolean('employee_consented')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index('scheduled_at');
        });

        Schema::create('disciplinary_rehabilitation_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('case_id')->nullable()->constrained('disciplinary_cases')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('behavior_score', 4, 1)->default(0);       // 0-10
            $table->decimal('attendance_score', 4, 1)->default(0);
            $table->decimal('performance_score', 4, 1)->default(0);
            $table->decimal('compliance_score', 4, 1)->default(0);
            $table->decimal('overall_score', 4, 1)->default(0);
            $table->enum('trend', ['improving', 'stable', 'declining'])->default('stable');
            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_rehabilitation_progress');
        Schema::dropIfExists('disciplinary_coaching_sessions');
        Schema::dropIfExists('disciplinary_corrective_actions');
    }
};
