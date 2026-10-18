<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rules defining what training new employees must complete during onboarding
        Schema::create('lms_onboarding_training_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->enum('rule_type', ['induction', 'department', 'role_based', 'policy'])->default('induction');
            // Scope: which new employees this rule applies to
            $table->enum('scope_type', ['all', 'department', 'position'])->default('all');
            $table->unsignedBigInteger('scope_id')->nullable();          // department/position id
            $table->string('scope_label', 100)->nullable();              // human-readable label
            $table->unsignedSmallInteger('due_within_days')->default(30);
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('blocks_onboarding_completion')->default(false);
            $table->unsignedTinyInteger('min_pass_score')->nullable();
            $table->unsignedTinyInteger('min_understanding_score')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index('rule_type');
        });

        // Per-employee training assignments generated when an onboarding plan is activated
        Schema::create('lms_onboarding_training_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('onboarding_plan_id')->index();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('lms_onboarding_training_rules')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->string('rule_type', 30)->default('induction');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed', 'overdue'])->default('pending');
            $table->date('due_date')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('pass_score', 5, 2)->nullable();
            $table->decimal('understanding_score', 5, 2)->nullable();
            $table->boolean('blocks_completion')->default(false);
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('notified_hr_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['onboarding_plan_id', 'user_id', 'rule_id'],
                'onboarding_training_unique'
            );
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_onboarding_training_assignments');
        Schema::dropIfExists('lms_onboarding_training_rules');
    }
};
