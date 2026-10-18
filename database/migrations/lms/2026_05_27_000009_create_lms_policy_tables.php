<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_policy_violations', function (Blueprint $table) {
            $table->id();

            // LMS context (at least one set)
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('lms_compliance_assignments')->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('lms_questionnaire_attempts')->nullOnDelete();
            $table->unsignedBigInteger('understanding_score_id')->nullable();

            // Link to central policy_violations record
            $table->foreignId('policy_violation_id')->nullable()->constrained('policy_violations')->nullOnDelete();

            $table->string('rule_name');
            $table->string('violation_type'); // mandatory_training_incomplete|cert_expired|pass_mark_not_met|understanding_below_threshold|restricted_action_blocked|custom
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('action_taken', ['blocked', 'warned', 'escalated', 'logged'])->default('logged');
            $table->text('description');
            $table->json('payload_snapshot')->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['enrollment_id', 'violation_type']);
            $table->index(['actor_id', 'action_taken']);
            $table->index(['is_resolved', 'created_at']);
        });

        Schema::create('lms_policy_exceptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('lms_compliance_rules')->nullOnDelete();
            $table->foreignId('policy_exception_id')->nullable()->constrained('policy_exceptions')->nullOnDelete();

            $table->string('context_type'); // enrollment|assignment|certificate|mandatory_training
            $table->unsignedBigInteger('context_id')->nullable();

            $table->text('reason');
            $table->string('evidence')->nullable(); // file path or URL
            $table->date('valid_from');
            $table->date('valid_until')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'expired', 'revoked'])->default('pending');

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['context_type', 'context_id']);
        });

        Schema::create('lms_policy_enforcement_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('lms_compliance_assignments')->nullOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('lms_questionnaire_attempts')->nullOnDelete();

            $table->string('action_checked');
            $table->integer('rules_evaluated')->default(0);
            $table->integer('violations_found')->default(0);
            $table->boolean('was_blocked')->default(false);
            $table->boolean('required_escalation')->default(false);
            $table->boolean('passed_clean')->default(true);

            $table->json('payload')->nullable();
            $table->json('enforcement_summary')->nullable();
            $table->json('violation_ids')->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at');

            $table->timestamps();

            $table->index(['actor_id', 'checked_at']);
            $table->index(['was_blocked', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_policy_enforcement_logs');
        Schema::dropIfExists('lms_policy_exceptions');
        Schema::dropIfExists('lms_policy_violations');
    }
};
