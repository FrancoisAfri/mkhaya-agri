<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project Management — project core (EPIC-020 projects, EPIC-021 WBS, EPIC-022 tasks).
 *
 *  pm_projects           the project itself; owns currency, timezone and working calendar.
 *  pm_wbs_nodes          single-table unlimited hierarchy (phase → deliverable → activity).
 *  pm_tasks              schedulable work, with the CPM engine's computed columns.
 *  pm_task_dependencies  FS/SS/FF/SF links with lag, the input to the critical-path pass.
 *
 * Hierarchy note: the reference DDL uses PostgreSQL `ltree` for subtree queries. This install has
 * only pgcrypto enabled and creating an extension needs superuser, so `path` is a materialised
 * text path ('/12/48/93/') with a text_pattern_ops index — subtree reads are a prefix LIKE, which
 * is index-backed and needs no extension. Same query shape, one less deployment prerequisite.
 *
 * Scheduling columns (early_*, late_*, float, is_critical) are *computed output* of the CPM engine
 * (PM-ADR-005), never user input. The engine itself touches no database: a service loads the graph,
 * calls the pure library, and writes the results back in one transaction.
 */
return new class extends Migration
{
    private const TABLES = ['pm_projects', 'pm_wbs_nodes', 'pm_tasks', 'pm_task_dependencies'];

    public function up(): void
    {
        $this->createProjects();
        $this->createWbsNodes();
        $this->createTasks();
        $this->createDependencies();

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function createProjects(): void
    {
        Schema::create('pm_projects', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();                       // PM-ADR-003: the only public id
            $t->unsignedBigInteger('company_id')->nullable()->index();

            // Org placement. Nullable because a tenant may not use branches/departments, and PM
            // must stand alone when the HR module is not licensed.
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('department_id')->nullable();
            $t->unsignedBigInteger('portfolio_id')->nullable();   // Phase 1 — column reserved, unused
            $t->unsignedBigInteger('program_id')->nullable();     // Phase 1 — column reserved, unused

            $t->string('code', 40);
            $t->string('name', 190);
            $t->text('description')->nullable();
            $t->string('lens_code', 30)->default('generic');  // industry lens (construction, ict, …)

            $t->string('status', 20)->default('draft');       // draft|active|on_hold|completed|cancelled|archived
            $t->string('stage', 20)->default('initiation');   // initiation|planning|execution|monitoring|closing
            $t->string('priority', 10)->default('medium');    // low|medium|high|critical

            // Client and manager are links, not FKs: the counterpart may live in CRM/HR or be
            // local-only when those modules are not installed (see pm_external_refs).
            $t->unsignedBigInteger('client_ref_id')->nullable();
            $t->unsignedBigInteger('manager_id')->nullable();

            $t->char('currency', 3);                          // PM-ADR-006: explicit, always
            $t->string('timezone', 64);                       // IANA; all timestamps stored UTC
            $t->unsignedBigInteger('calendar_id');

            $t->date('planned_start')->nullable();
            $t->date('planned_end')->nullable();
            $t->date('actual_start')->nullable();
            $t->date('actual_end')->nullable();

            $t->string('location_name', 190)->nullable();
            $t->decimal('geo_lat', 9, 6)->nullable();
            $t->decimal('geo_lng', 9, 6)->nullable();

            $t->decimal('budget_amount', 18, 2)->nullable();
            $t->boolean('is_billable')->default(true);

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('client_ref_id')->references('id')->on('pm_external_refs')->nullOnDelete();
            $t->foreign('calendar_id')->references('id')->on('pm_calendars');

            $t->index(['company_id', 'status']);
            $t->index(['company_id', 'manager_id']);
        });

        // Project codes are quoted to clients and printed on documents: unique per tenant, but a
        // code freed by deletion can be reused (PM-ADR-007).
        DB::statement('CREATE UNIQUE INDEX pm_projects_code_unique ON pm_projects (company_id, code) WHERE deleted_at IS NULL');
    }

    private function createWbsNodes(): void
    {
        Schema::create('pm_wbs_nodes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('parent_id')->nullable();

            $t->string('node_type', 20)->default('activity'); // phase|deliverable|activity|task_group
            $t->string('wbs_code', 40);                       // '1.2.3', generated from position
            $t->string('path', 500);                          // '/12/48/93/' — materialised ancestry
            $t->unsignedInteger('depth')->default(0);
            $t->integer('sort_order')->default(0);

            $t->string('name', 190);
            $t->text('description')->nullable();
            $t->boolean('is_milestone')->default(false);

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('parent_id')->references('id')->on('pm_wbs_nodes')->nullOnDelete();

            $t->index(['company_id', 'project_id', 'sort_order']);
        });

        DB::statement('CREATE UNIQUE INDEX pm_wbs_nodes_code_unique ON pm_wbs_nodes (company_id, project_id, wbs_code) WHERE deleted_at IS NULL');
        // Subtree reads: WHERE path LIKE '/12/48/%' — text_pattern_ops makes the prefix indexable.
        DB::statement('CREATE INDEX pm_wbs_nodes_path_idx ON pm_wbs_nodes (path text_pattern_ops)');
    }

    private function createTasks(): void
    {
        Schema::create('pm_tasks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();                       // client-generatable offline (PM-ADR-004)
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('wbs_node_id')->nullable();
            $t->unsignedBigInteger('parent_task_id')->nullable();

            $t->string('code', 40);
            $t->string('name', 190);
            $t->text('description')->nullable();

            $t->string('status', 20)->default('not_started'); // not_started|in_progress|blocked|done|cancelled
            $t->string('priority', 10)->default('medium');

            // ── Scheduling input ──────────────────────────────────────────────
            $t->string('schedule_mode', 10)->default('auto'); // auto = engine sets dates; manual = user pins them
            $t->string('constraint_type', 10)->default('asap'); // asap|alap|snet|snlt|fnet|fnlt|mso|mfo
            $t->date('constraint_date')->nullable();
            $t->unsignedInteger('duration_minutes')->default(0);
            $t->unsignedBigInteger('calendar_id')->nullable(); // falls back to the project calendar

            $t->timestamp('planned_start')->nullable();
            $t->timestamp('planned_end')->nullable();
            $t->timestamp('commit_start')->nullable();        // P80 commitment — Phase 2, reserved
            $t->timestamp('commit_end')->nullable();
            $t->timestamp('actual_start')->nullable();
            $t->timestamp('actual_end')->nullable();

            // ── Scheduling output (CPM engine only — never user input) ─────────
            $t->timestamp('early_start')->nullable();
            $t->timestamp('early_finish')->nullable();
            $t->timestamp('late_start')->nullable();
            $t->timestamp('late_finish')->nullable();
            $t->integer('total_float_minutes')->nullable();
            $t->integer('free_float_minutes')->nullable();
            $t->boolean('is_critical')->default(false);
            $t->timestamp('scheduled_at')->nullable();        // when the engine last wrote these

            $t->decimal('estimated_hours', 10, 2)->nullable();
            // Mirrored from pm_derived_values for list/Gantt reads; the ledger stays the source of
            // truth and progress_source records which one won.
            $t->decimal('progress_pct', 5, 2)->default(0);
            $t->string('progress_source', 10)->default('manual'); // manual | derived

            $t->decimal('budget_amount', 18, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->boolean('is_milestone')->default(false);

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('wbs_node_id')->references('id')->on('pm_wbs_nodes')->nullOnDelete();
            $t->foreign('parent_task_id')->references('id')->on('pm_tasks')->nullOnDelete();

            // The Gantt's read path, and the "what's critical" filter.
            $t->index(['company_id', 'project_id', 'status']);
            $t->index(['company_id', 'project_id', 'planned_start']);
            $t->index(['company_id', 'project_id', 'is_critical']);
            $t->index(['company_id', 'wbs_node_id']);
        });

        DB::statement('CREATE UNIQUE INDEX pm_tasks_code_unique ON pm_tasks (company_id, project_id, code) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE pm_tasks ADD CONSTRAINT pm_tasks_progress_chk CHECK (progress_pct >= 0 AND progress_pct <= 100)');
        DB::statement('ALTER TABLE pm_tasks ADD CONSTRAINT pm_tasks_currency_chk CHECK (budget_amount IS NULL OR currency IS NOT NULL)');
    }

    private function createDependencies(): void
    {
        Schema::create('pm_task_dependencies', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('predecessor_id');
            $t->unsignedBigInteger('successor_id');
            $t->string('dep_type', 2)->default('fs');   // fs|ss|ff|sf
            $t->integer('lag_minutes')->default(0);     // negative = lead
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('predecessor_id')->references('id')->on('pm_tasks')->cascadeOnDelete();
            $t->foreign('successor_id')->references('id')->on('pm_tasks')->cascadeOnDelete();

            // Both directions are read during the forward and backward passes.
            $t->index(['company_id', 'project_id', 'predecessor_id']);
            $t->index(['company_id', 'project_id', 'successor_id']);
        });

        DB::statement('CREATE UNIQUE INDEX pm_task_dependencies_unique ON pm_task_dependencies (company_id, predecessor_id, successor_id) WHERE deleted_at IS NULL');
        // A task cannot depend on itself. Longer cycles are caught by the engine, which reports
        // the offending path rather than looping.
        DB::statement('ALTER TABLE pm_task_dependencies ADD CONSTRAINT pm_task_dependencies_self_chk CHECK (predecessor_id <> successor_id)');
        DB::statement("ALTER TABLE pm_task_dependencies ADD CONSTRAINT pm_task_dependencies_type_chk CHECK (dep_type IN ('fs','ss','ff','sf'))");
    }

    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
