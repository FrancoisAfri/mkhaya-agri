<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_approval_levels', function (Blueprint $table) {
            // Parallel approvals: approvers in the same group approve concurrently;
            // the chain only advances when all members of the group are approved.
            $table->unsignedTinyInteger('parallel_group')->nullable()->after('is_required')
                ->comment('Non-null = parallel approvals; all members of same group must approve');

            // Whether this approver was resolved via an active delegation
            $table->boolean('is_delegated')->default(false)->after('parallel_group');

            // SLA deadline for this approval level
            $table->timestamp('sla_due_at')->nullable()->after('is_delegated');

            // Policy override: HR can force-approve a flagged request
            $table->boolean('is_policy_override')->default(false)->after('sla_due_at');
            $table->text('override_reason')->nullable()->after('is_policy_override');

            $table->index('sla_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('leave_approval_levels', function (Blueprint $table) {
            $table->dropColumn(['parallel_group', 'is_delegated', 'sla_due_at', 'is_policy_override', 'override_reason']);
        });
    }
};
