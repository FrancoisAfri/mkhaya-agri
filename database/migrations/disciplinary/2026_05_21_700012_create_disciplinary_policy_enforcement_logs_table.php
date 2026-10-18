<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_policy_enforcement_logs', function (Blueprint $table) {
            $table->id();

            // Context
            $table->foreignId('case_id')->nullable()->constrained('disciplinary_cases')->nullOnDelete();
            $table->foreignId('hearing_id')->nullable()->constrained('disciplinary_hearings')->nullOnDelete();
            $table->foreignId('investigation_id')->nullable()->constrained('disciplinary_investigations')->nullOnDelete();

            // What was checked
            $table->string('action_checked');         // e.g. disciplinary.case.created
            $table->integer('rules_evaluated')->default(0);
            $table->integer('violations_found')->default(0);

            // Outcome flags
            $table->boolean('was_blocked')->default(false);
            $table->boolean('required_escalation')->default(false);
            $table->boolean('passed_clean')->default(true); // no violations at all

            // Payload and detail
            $table->json('payload')->nullable();       // the event payload that was evaluated
            $table->json('enforcement_summary')->nullable(); // array of {rule, outcome, severity, explanation}
            $table->json('violation_ids')->nullable();  // IDs of disciplinary_policy_violations created

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->useCurrent();

            $table->timestamps();

            $table->index(['case_id', 'action_checked']);
            $table->index(['actor_id', 'was_blocked']);
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_policy_enforcement_logs');
    }
};
