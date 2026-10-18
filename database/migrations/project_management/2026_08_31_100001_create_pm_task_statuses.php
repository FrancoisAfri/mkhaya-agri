<?php

use App\Modules\Operations\ProjectManagement\Models\Task;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The steps a project's work moves through, defined by the people running it.
 *
 * Every project had the same five columns: not started, in progress, blocked, done, cancelled. Real
 * work does not divide that way — a construction job wants "awaiting materials" and "ready for
 * inspection", a design team wants "in review", and a board that cannot say those things gets
 * worked around in the task title.
 *
 * The important constraint is that a task's status is not merely a label here. It drives the
 * evidence ledger (task.started, task.completed, task.reopened), progress derivation, what counts
 * as open work, invoicing and claims, and the scheduler. Letting somebody invent a status with no
 * meaning would quietly break all of it — a project whose statuses were "Blue" and "Green" would
 * emit no completion events, roll up no progress and claim nothing.
 *
 * So a step is a **column with a category**. Its category is one of the five built-in states, and
 * that is what every downstream consumer keeps reading. "Awaiting materials" is a step whose
 * category is `blocked`; "In review" is one whose category is `in_progress`. The board shows the
 * steps; the ledger sees the meaning. Nothing downstream of this migration has to change.
 *
 * A project with no steps configured falls back to the five built-ins, so this is invisible until
 * somebody chooses to use it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_task_statuses', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');

            // Stable across renames: the tasks sitting in a column reference this, so retitling
            // "In review" to "With the engineer" must not strand every task in it.
            $t->string('key', 40);
            $t->string('label', 60);

            // What the step *means*. One of Task::BOARD_STATUSES, and the only thing the evidence
            // ledger, the derivation engine and the claim pack ever look at.
            $t->string('category', 20);

            $t->integer('position')->default(0);
            // Where a task lands when nobody says otherwise. Exactly one per project.
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->string('colour', 20)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'project_id', 'position']);
        });

        Schema::table('pm_tasks', function (Blueprint $t) {
            /*
             * Which column the task is in. Null means "the built-in column named by `status`",
             * which is every task that exists today and every task on a project that has not
             * defined its own steps.
             *
             * `status` is deliberately left in place and still authoritative for meaning. Replacing
             * it with a foreign key would have made the semantic state unreadable without a join,
             * in exactly the queries — progress rollup, open-work counts, claim eligibility — that
             * most need to stay cheap.
             */
            $t->string('status_key', 40)->nullable()->after('status');
            $t->index(['project_id', 'status_key']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // One row per key per project, and one default. Both are invariants the UI relies on:
            // two columns with the same key would make a card's position ambiguous, and two
            // defaults would make where a new task lands a matter of insertion order.
            DB::statement('CREATE UNIQUE INDEX pm_task_statuses_key_unique
                ON pm_task_statuses (project_id, key) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX pm_task_statuses_default_unique
                ON pm_task_statuses (project_id) WHERE is_default AND deleted_at IS NULL');

            $categories = "'" . implode("','", Task::BOARD_STATUSES) . "'";
            DB::statement("ALTER TABLE pm_task_statuses
                ADD CONSTRAINT pm_task_statuses_category_chk CHECK (category IN ({$categories}))");

            $this->applyTenantRls(['pm_task_statuses']);
        }
    }

    public function down(): void
    {
        Schema::table('pm_tasks', function (Blueprint $t) {
            $t->dropIndex(['project_id', 'status_key']);
            $t->dropColumn('status_key');
        });

        Schema::dropIfExists('pm_task_statuses');
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
