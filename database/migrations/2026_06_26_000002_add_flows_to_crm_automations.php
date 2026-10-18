<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — multi-step automation flows. Workflows gain an ordered list of
 * steps (action / wait / condition-gate); crm_flow_runs tracks each entity's
 * position in a flow and when to resume after a wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_workflows') && ! Schema::hasColumn('crm_workflows', 'steps')) {
            Schema::table('crm_workflows', function (Blueprint $t) {
                $t->json('steps')->nullable();
            });
        }

        if (! Schema::hasTable('crm_flow_runs')) {
            Schema::create('crm_flow_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workflow_id')->index();
                $t->string('entity_type');
                $t->unsignedBigInteger('entity_id');
                $t->unsignedInteger('current_step')->default(0);
                $t->string('status')->default('active')->index();
                $t->timestamp('next_run_at')->nullable()->index();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->text('last_error')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_flow_runs');

        if (Schema::hasTable('crm_workflows') && Schema::hasColumn('crm_workflows', 'steps')) {
            Schema::table('crm_workflows', fn (Blueprint $t) => $t->dropColumn('steps'));
        }
    }
};
