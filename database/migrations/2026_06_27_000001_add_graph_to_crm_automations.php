<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — free-form visual canvas. Workflows gain a node/edge `graph`, and
 * flow runs gain a `current_node` cursor for the node-based graph runner.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_workflows') && ! Schema::hasColumn('crm_workflows', 'graph')) {
            Schema::table('crm_workflows', fn (Blueprint $t) => $t->json('graph')->nullable());
        }

        if (Schema::hasTable('crm_flow_runs') && ! Schema::hasColumn('crm_flow_runs', 'current_node')) {
            Schema::table('crm_flow_runs', fn (Blueprint $t) => $t->string('current_node')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_workflows') && Schema::hasColumn('crm_workflows', 'graph')) {
            Schema::table('crm_workflows', fn (Blueprint $t) => $t->dropColumn('graph'));
        }

        if (Schema::hasTable('crm_flow_runs') && Schema::hasColumn('crm_flow_runs', 'current_node')) {
            Schema::table('crm_flow_runs', fn (Blueprint $t) => $t->dropColumn('current_node'));
        }
    }
};
