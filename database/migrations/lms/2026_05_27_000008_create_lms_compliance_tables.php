<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rules that define who must complete which course and when
        Schema::create('lms_compliance_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('name');
            $table->text('description')->nullable();

            // Scope: who this rule applies to
            $table->string('scope_type')->default('all'); // all|position|department|location|employee
            $table->unsignedBigInteger('scope_id')->nullable();   // position_id | user_id | division integer
            $table->string('scope_value')->nullable();            // human label for display

            // Timing
            $table->string('frequency')->default('once');         // once|annual|biannual|quarterly|custom
            $table->unsignedInteger('frequency_days')->nullable(); // for custom
            $table->unsignedInteger('due_within_days')->default(30);
            $table->unsignedInteger('grace_period_days')->default(7);

            // Reminders: JSON array of days-before-due to fire e.g. [30,14,7,1]
            $table->jsonb('reminder_days_before')->default('[30,14,7,1]');

            // Escalation
            $table->unsignedInteger('escalate_after_days')->default(3);
            $table->string('escalate_to_role')->default('manager'); // manager|hr|admin

            // Actions blocked when overdue (JSON array of action keys)
            $table->jsonb('restrict_actions')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('course_id')->references('id')->on('lms_courses')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['scope_type', 'scope_id', 'is_active']);
        });

        // Per-employee assignments generated from rules
        Schema::create('lms_compliance_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->date('assigned_at');
            $table->date('due_date');
            $table->timestamp('completed_at')->nullable();
            $table->string('status')->default('pending'); // pending|in_progress|completed|overdue|waived
            $table->unsignedBigInteger('waived_by')->nullable();
            $table->text('waived_reason')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->date('compliance_period_start')->nullable();
            $table->date('compliance_period_end')->nullable();
            $table->timestamps();

            $table->foreign('rule_id')->references('id')->on('lms_compliance_rules')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('lms_courses')->cascadeOnDelete();
            $table->foreign('enrollment_id')->references('id')->on('lms_enrollments')->nullOnDelete();
            $table->foreign('waived_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['rule_id', 'user_id', 'compliance_period_start'], 'compliance_assignment_unique');
            $table->index(['user_id', 'status']);
            $table->index(['due_date', 'status']);
        });

        // Log of reminders sent
        Schema::create('lms_training_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('user_id');
            $table->string('reminder_type'); // upcoming_due|overdue|escalation
            $table->integer('days_relative');  // positive = days until due, negative = days overdue
            $table->timestamp('sent_at')->useCurrent();
            $table->string('sent_via')->default('database'); // email|database|both
            $table->timestamps();

            $table->foreign('assignment_id')->references('id')->on('lms_compliance_assignments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['assignment_id', 'reminder_type']);
        });

        // Escalation records when training remains overdue
        Schema::create('lms_compliance_escalations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('escalated_to_id')->nullable();
            $table->string('escalated_to_role'); // manager|hr|admin
            $table->unsignedInteger('days_overdue');
            $table->text('note')->nullable();
            $table->string('status')->default('open'); // open|acknowledged|resolved
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('escalated_at')->useCurrent();
            $table->timestamps();

            $table->foreign('assignment_id')->references('id')->on('lms_compliance_assignments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('escalated_to_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['assignment_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        // Cached compliance report snapshots
        Schema::create('lms_compliance_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_type'); // overall|position|department|individual
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('scope_name')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('total_employees')->default(0);
            $table->unsignedInteger('total_assignments')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('overdue_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->decimal('compliance_rate', 5, 2)->default(0);
            $table->jsonb('report_data')->nullable();
            $table->timestamp('generated_at')->useCurrent();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamps();

            $table->foreign('generated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['report_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_compliance_reports');
        Schema::dropIfExists('lms_compliance_escalations');
        Schema::dropIfExists('lms_training_reminders');
        Schema::dropIfExists('lms_compliance_assignments');
        Schema::dropIfExists('lms_compliance_rules');
    }
};
