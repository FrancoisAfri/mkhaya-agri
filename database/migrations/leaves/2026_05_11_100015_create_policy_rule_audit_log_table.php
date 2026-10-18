<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for every policy rule evaluation against a leave request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_rule_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('hr_people')->nullOnDelete();
            $table->foreignId('policy_rule_id')->nullable()->constrained('policy_leave_rules')->nullOnDelete();

            $table->enum('event_type', [
                'evaluation',
                'override_requested',
                'override_approved',
                'override_rejected',
                'rule_triggered',
                'rule_skipped',
                'conflict_detected',
            ]);

            $table->string('rule_code', 100)->nullable();

            $table->enum('evaluation_result', ['pass', 'fail', 'warning', 'override']);
            $table->text('evaluation_detail')->nullable();

            // Override chain
            $table->foreignId('override_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('override_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();

            // AI involvement
            $table->boolean('ai_used')->default(false);
            $table->text('ai_explanation')->nullable();

            // Append-only
            $table->timestamp('created_at')->useCurrent();

            $table->index('leave_request_id');
            $table->index(['employee_id', 'event_type']);
            $table->index('created_at');
            $table->index(['rule_code', 'evaluation_result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_rule_audit_log');
    }
};
