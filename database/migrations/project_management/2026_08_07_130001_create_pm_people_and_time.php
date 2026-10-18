<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who does the work, and how long it took (EPIC-030, EPIC-031).
 *
 *  pm_task_assignments  who is on a task, and for how much of their time
 *  pm_time_entries      hours booked against a task, and whether anyone approved them
 *
 * Assignments carry an allocation percentage rather than a simple yes/no, because "Thabo is on
 * this" is not the same claim as "Thabo is on this full time", and a resource report built on the
 * first is fiction.
 */
return new class extends Migration
{
    private const TABLES = ['pm_task_assignments', 'pm_time_entries'];

    public function up(): void
    {
        Schema::create('pm_task_assignments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('task_id');
            // A member, not a user: a subcontractor's foreman may have no login (see
            // pm_project_members). Identity hangs off the member row either way.
            $t->unsignedBigInteger('member_id');
            $t->string('role', 60)->nullable();          // "Foreman", "Plant operator"
            // Percent of the person's working day this task is expected to take. 100 = full time.
            $t->unsignedSmallInteger('allocation_pct')->default(100);
            $t->boolean('is_lead')->default(false);      // the one person answerable for it
            $t->timestamp('assigned_at')->nullable();
            $t->unsignedBigInteger('assigned_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('task_id')->references('id')->on('pm_tasks')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('member_id')->references('id')->on('pm_project_members')->cascadeOnDelete();
            $t->index(['company_id', 'task_id']);
            $t->index(['company_id', 'member_id']);
        });

        Schema::create('pm_time_entries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('task_id')->nullable();   // project-level time is legitimate
            $t->unsignedBigInteger('member_id');
            $t->date('worked_on');
            $t->decimal('hours', 6, 2);
            $t->string('note', 500)->nullable();
            // draft → submitted → approved | rejected. Only approved hours reach the TimeSink and
            // count as verified in derivation (PM-ADR-010).
            $t->string('status', 20)->default('draft');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('rejection_reason', 255)->nullable();
            // What the sink gave back when the hours were posted — the receipt.
            $t->string('sink_reference', 60)->nullable();
            // Costed at the rate in force when the work was done, not today's.
            $t->decimal('rate_per_hour', 18, 2)->nullable();
            $t->decimal('cost_amount', 18, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('task_id')->references('id')->on('pm_tasks')->nullOnDelete();
            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('member_id')->references('id')->on('pm_project_members')->cascadeOnDelete();
            $t->index(['company_id', 'project_id', 'worked_on']);
            $t->index(['company_id', 'member_id', 'worked_on']);
            $t->index(['company_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // One assignment per person per task. Two rows would double them in every resource
            // report, and "assigned twice" means nothing.
            DB::statement('CREATE UNIQUE INDEX pm_task_assignments_unique ON pm_task_assignments (task_id, member_id) WHERE deleted_at IS NULL');
            DB::statement("ALTER TABLE pm_time_entries ADD CONSTRAINT pm_time_entries_status_chk CHECK (status IN ('draft','submitted','approved','rejected'))");
            // A day has 24 hours in it, and a negative day has none.
            DB::statement('ALTER TABLE pm_time_entries ADD CONSTRAINT pm_time_entries_hours_chk CHECK (hours > 0 AND hours <= 24)');
            DB::statement('ALTER TABLE pm_task_assignments ADD CONSTRAINT pm_task_assignments_allocation_chk CHECK (allocation_pct > 0 AND allocation_pct <= 100)');

            $this->applyTenantRls(self::TABLES);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
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
