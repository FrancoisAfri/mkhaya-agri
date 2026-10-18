<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── offboarding_templates ──────────────────────────────────────────────
        Schema::create('offboarding_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->integer('notice_period_days')->default(30);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        // ── offboarding_template_tasks ─────────────────────────────────────────
        Schema::create('offboarding_template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('offboarding_templates')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->enum('category', ['hr', 'it', 'finance', 'facilities', 'manager']);
            $table->string('assigned_role')->nullable();
            $table->integer('days_before_last_day')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // ── offboarding_plans ──────────────────────────────────────────────────
        Schema::create('offboarding_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hr_person_id')->constrained('hr_persons')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('offboarding_templates')->nullOnDelete();
            $table->enum('termination_type', [
                'resignation', 'retrenchment', 'dismissal', 'retirement', 'contract_end', 'death',
            ]);
            $table->date('resignation_date')->nullable();
            $table->date('last_working_day')->nullable();
            $table->enum('status', ['pending', 'active', 'completed', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('hr_person_id');
        });

        // ── offboarding_tasks ──────────────────────────────────────────────────
        Schema::create('offboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('offboarding_plans')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->enum('category', ['hr', 'it', 'finance', 'facilities', 'manager']);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'skipped'])->default('pending');
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // ── offboarding_exit_interviews ────────────────────────────────────────
        Schema::create('offboarding_exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('plan_id')->unique()->constrained('offboarding_plans')->cascadeOnDelete();
            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('interview_date')->nullable();
            $table->enum('reason_for_leaving', [
                'better_opportunity', 'salary', 'work_environment', 'management',
                'relocation', 'personal', 'retirement', 'contract_end', 'other',
            ]);
            $table->tinyInteger('satisfaction_score')->nullable()->comment('1-5');
            $table->boolean('would_recommend')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('additional_feedback')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_exit_interviews');
        Schema::dropIfExists('offboarding_tasks');
        Schema::dropIfExists('offboarding_plans');
        Schema::dropIfExists('offboarding_template_tasks');
        Schema::dropIfExists('offboarding_templates');
    }
};
