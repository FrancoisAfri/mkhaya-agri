<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — Automation & Workflow engine (spec section 3).
 *
 * A workflow is a trigger + a set of conditions + a set of actions. Conditions
 * and actions are stored as JSON so the (future) visual builder can compose them
 * without schema changes. Every execution is recorded in crm_workflow_runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('trigger', 40);                 // lead.created|deal.created|deal.stage_changed|deal.inactive|activity.completed|deal.sla_breached
            $table->json('trigger_config')->nullable();     // e.g. {"stage_id":3} or {"inactive_days":14}
            $table->string('match_type', 4)->default('all'); // all|any
            $table->json('conditions')->nullable();         // [{field,operator,value}]
            $table->json('actions')->nullable();            // [{type,...config}]
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('run_order')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['trigger', 'is_active']);
        });

        Schema::create('crm_workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('crm_workflows')->cascadeOnDelete();
            $table->string('entity_type', 20);
            $table->unsignedBigInteger('entity_id');
            $table->string('status', 12);                  // success|failed|skipped
            $table->json('detail')->nullable();            // actions executed / error / skip reason
            $table->timestamps();

            $table->index(['workflow_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_workflow_runs');
        Schema::dropIfExists('crm_workflows');
    }
};
