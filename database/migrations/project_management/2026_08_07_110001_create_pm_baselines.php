<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baselines: the schedule as it was agreed, kept so that "we are three weeks late" has something
 * to be late against (PM-1233).
 *
 * Snapshot rows, not references. A baseline that pointed at the live tasks would move with them,
 * which is the one thing a baseline must never do — and the reason a project with no baseline can
 * always be shown to be on time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_baselines', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->string('name', 120);                       // "Contract award", "Rev C"
            $t->string('reason', 255)->nullable();         // why it was re-baselined
            $t->timestamp('captured_at');
            $t->unsignedBigInteger('captured_by')->nullable();
            // The whole-project figures, so a portfolio view needs no join to the task snapshot.
            $t->timestamp('planned_start')->nullable();
            $t->timestamp('planned_finish')->nullable();
            $t->unsignedInteger('task_count')->default(0);
            $t->decimal('budget_amount', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            // Exactly one baseline is the one variance is reported against; the others are history.
            $t->boolean('is_current')->default(true);
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'project_id', 'is_current']);
        });

        Schema::create('pm_baseline_tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('baseline_id');
            // The task as it was. Kept by id AND by code: a task deleted after baselining still has
            // to appear in variance as work that was promised and then dropped.
            $t->unsignedBigInteger('task_id')->nullable();
            $t->string('task_code', 40)->nullable();
            $t->string('name', 190);
            $t->timestamp('planned_start')->nullable();
            $t->timestamp('planned_end')->nullable();
            $t->unsignedInteger('duration_minutes')->default(0);
            $t->boolean('is_critical')->default(false);
            $t->boolean('is_milestone')->default(false);
            $t->decimal('budget_amount', 18, 2)->nullable();
            $t->timestamps();

            $t->foreign('baseline_id')->references('id')->on('pm_baselines')->cascadeOnDelete();
            $t->index(['company_id', 'baseline_id']);
            $t->index(['baseline_id', 'task_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // One current baseline per project. A partial unique index rather than application
            // logic: two "current" baselines would make every variance figure ambiguous, and that
            // is not a state worth being able to reach.
            DB::statement('CREATE UNIQUE INDEX pm_baselines_one_current ON pm_baselines (project_id) WHERE is_current AND deleted_at IS NULL');

            $this->applyTenantRls(['pm_baselines', 'pm_baseline_tasks']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_baseline_tasks');
        Schema::dropIfExists('pm_baselines');
    }

    private function applyTenantRls(array $tables): void
    {
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
