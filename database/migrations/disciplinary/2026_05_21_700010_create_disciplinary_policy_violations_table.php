<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_policy_violations', function (Blueprint $table) {
            $table->id();

            // Disciplinary context (at least one must be set)
            $table->foreignId('case_id')->nullable()->constrained('disciplinary_cases')->nullOnDelete();
            $table->foreignId('hearing_id')->nullable()->constrained('disciplinary_hearings')->nullOnDelete();
            $table->foreignId('investigation_id')->nullable()->constrained('disciplinary_investigations')->nullOnDelete();

            // Link to the central policy_violations record (set when rule engine fires)
            $table->foreignId('policy_violation_id')->nullable()->constrained('policy_violations')->nullOnDelete();

            // Enforcement detail
            $table->string('rule_name');
            $table->string('violation_type'); // warning_expiry|suspension_action|hearing_requirement|dismissal_approval|chairperson_conflict|sanction_limit|progressive_discipline|custom
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('action_taken', ['blocked', 'warned', 'escalated', 'logged'])->default('logged');
            $table->text('description');
            $table->json('payload_snapshot')->nullable(); // the event payload at check time

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // Resolution
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['case_id', 'violation_type']);
            $table->index(['actor_id', 'action_taken']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_policy_violations');
    }
};
