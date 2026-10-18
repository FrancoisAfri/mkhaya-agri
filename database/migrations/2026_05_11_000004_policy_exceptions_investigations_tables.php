<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skip if tables already exist (defined in earlier PHASE 7 migrations)
        if (Schema::hasTable('policy_exceptions') || 
            Schema::hasTable('policy_violation_investigations') ||
            Schema::hasTable('policy_investigation_notes') ||
            Schema::hasTable('policy_investigation_evidence')) {
            return;
        }

        // Policy Exception Requests (PHASE 7)
        Schema::create('policy_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('policy_id')
                ->constrained('policy')
                ->onDelete('cascade');
            $table->foreignId('rule_id')
                ->constrained('policy_rules')
                ->onDelete('cascade');

            // Exception request details
            $table->text('reason');                 // Why exception needed
            $table->date('start_date');             // When exception applies
            $table->date('end_date')->nullable();   // Until when (null = permanent)
            $table->json('evidence')->nullable();   // File paths/URLs for justification
            $table->integer('duration_days')->nullable(); // Calculated duration

            // Approval workflow
            $table->enum('status', [
                'draft',           // User creating request
                'submitted',       // Ready for approval
                'approved',        // Manager approved
                'rejected',        // Manager rejected
                'escalated',       // Escalated to policy owner
                'expired',         // Exception expired
            ])->default('draft');

            $table->foreignId('approved_by')->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();   // Manager's notes
            $table->text('rejection_reason')->nullable(); // Why rejected

            // Auto-expiry
            $table->boolean('auto_notify_before_expiry')->default(true);
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            // Metrics
            $table->integer('violation_count')->default(0);  // How many violations bypassed
            $table->integer('usage_count')->default(0);      // How many times used

            // Audit
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->softDeletes();

            // Indexes
            $table->index('user_id');
            $table->index('policy_id');
            $table->index('status');
            $table->index('start_date');
            $table->index('end_date');
            $table->index('approved_by');
        });

        // Violation Investigation (PHASE 8)
        Schema::create('policy_violation_investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('violation_id')
                ->constrained('policy_violations')
                ->onDelete('cascade');
            $table->foreignId('policy_id')
                ->constrained('policy')
                ->onDelete('cascade');
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // Investigation details
            $table->enum('status', [
                'open',           // Needs investigation
                'in_progress',    // Being investigated
                'reviewing',      // Under review
                'resolved',       // Resolved
                'closed',         // Closed
            ])->default('open');

            // Who's investigating
            $table->foreignId('assigned_to')->nullable()
                ->constrained('users')
                ->onDelete('set null');
            $table->timestamp('assigned_at')->nullable();

            // Findings
            $table->text('initial_notes')->nullable();      // What investigator found
            $table->text('findings')->nullable();           // Official findings
            $table->enum('severity_finding', [
                'resolved_satisfactorily',
                'requires_training',
                'requires_discipline',
                'repeat_offender',
                'systemic_issue',
            ])->nullable();

            // Resolution
            $table->text('resolution_action')->nullable();  // What was done
            $table->enum('resolution_type', [
                'no_action',
                'coaching',
                'training',
                'warning',
                'suspension',
                'termination',
                'policy_change',
            ])->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()
                ->constrained('users')
                ->onDelete('set null');

            // Audit
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            // Indexes
            $table->index('violation_id');
            $table->index('user_id');
            $table->index('status');
            $table->index('assigned_to');
        });

        // Investigation comments/notes
        Schema::create('policy_investigation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')
                ->constrained('policy_violation_investigations')
                ->onDelete('cascade');
            $table->foreignId('created_by')
                ->constrained('users')
                ->onDelete('cascade');

            $table->text('note');
            $table->json('attachments')->nullable();  // File references
            $table->enum('visibility', [
                'internal',   // Only investigators see
                'user',       // User can see
                'manager',    // Manager & investigators
            ])->default('internal');

            $table->timestamps();

            $table->index('investigation_id');
            $table->index('created_by');
        });

        // Evidence/attachments for investigations
        Schema::create('policy_investigation_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')
                ->constrained('policy_violation_investigations')
                ->onDelete('cascade');

            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_type');              // pdf, doc, image, etc.
            $table->unsignedInteger('file_size');
            $table->text('description')->nullable();

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->onDelete('cascade');

            $table->timestamps();

            $table->index('investigation_id');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_investigation_evidence');
        Schema::dropIfExists('policy_investigation_notes');
        Schema::dropIfExists('policy_violation_investigations');
        Schema::dropIfExists('policy_exceptions');
    }
};
