<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable approval routing (EPIC-090).
 *
 * "Submit for approval" was a button with nothing behind it: hours went to `submitted` and waited
 * for whoever happened to open the Delivery page. That is not an approval process, and it is not
 * one that survives being described to an auditor.
 *
 * Companies do not agree on who signs off what, so the shape is designed rather than coded:
 *
 *  pm_approval_flows     one per (tenant, subject type) — timesheets, expenses, documents, a
 *                        project stage change. Optional threshold so small claims skip the queue.
 *  pm_approval_steps     the ladder. Ordered; each step names *who* by rule, not by person, so a
 *                        flow keeps working after somebody leaves.
 *  pm_approval_requests  one live request per submitted thing, carrying which step it is on.
 *  pm_approval_actions   every decision, append-only in practice: the record of who said yes.
 *
 * Rules, not names. `approver_type` is one of:
 *   role          a platform role (spatie) — "anyone with Finance Manager"
 *   pm_role       a PM project role — "the project manager of this project"
 *   user          a named person, for the small company that genuinely wants one
 *   manager       the submitter's line manager, resolved from HR when it is installed
 *
 * A step can require more than one approval (`min_approvals`) — a two-signature rule for anything
 * above a threshold is common and cannot be expressed by a single approver.
 */
return new class extends Migration
{
    private const TABLES = ['pm_approval_flows', 'pm_approval_steps', 'pm_approval_requests', 'pm_approval_actions'];

    public function up(): void
    {
        Schema::create('pm_approval_flows', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 120);
            $t->string('subject_type', 30);          // timesheet | expense | document | project_stage
            $t->boolean('is_active')->default(true);
            // Below this, nothing is routed: it is approved on submission. Null = everything routes.
            $t->decimal('threshold_amount', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            // Optional narrowing to one project; null means the whole tenant.
            $t->unsignedBigInteger('project_id')->nullable()->index();
            // What happens if nobody acts. Null = wait indefinitely, which is at least honest.
            $t->unsignedSmallInteger('escalate_after_hours')->nullable();
            $t->text('description')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'subject_type', 'is_active']);
        });

        Schema::create('pm_approval_steps', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('flow_id');
            $t->unsignedSmallInteger('position');    // 1-based; the ladder is climbed in order
            $t->string('name', 120);
            $t->string('approver_type', 20);         // role | pm_role | user | manager
            $t->string('approver_ref', 120)->nullable();  // role name, pm role key, or user id
            $t->unsignedSmallInteger('min_approvals')->default(1);
            // Whether the person who submitted may approve their own step. Off by default: a
            // separation-of-duties rule that has to be switched on deliberately.
            $t->boolean('allow_self_approval')->default(false);
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('flow_id')->references('id')->on('pm_approval_flows')->cascadeOnDelete();
            $t->index(['company_id', 'flow_id', 'position']);
        });

        Schema::create('pm_approval_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('flow_id')->nullable();
            $t->unsignedBigInteger('project_id')->nullable()->index();
            $t->string('subject_type', 30);
            $t->unsignedBigInteger('subject_id');
            $t->uuid('subject_uuid')->nullable()->index();
            $t->string('subject_label', 190)->nullable();  // denormalised: the inbox reads without
                                                           // joining four tables per row
            $t->decimal('amount', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('status', 20)->default('pending');  // pending | approved | rejected | cancelled
            $t->unsignedSmallInteger('current_position')->default(1);
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->text('note')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('flow_id')->references('id')->on('pm_approval_flows')->nullOnDelete();
            $t->index(['company_id', 'status', 'current_position']);
            $t->index(['company_id', 'subject_type', 'subject_id']);
        });

        // One live request per thing. Re-submitting a rejected timesheet opens a new one; a second
        // request while the first is pending is a double-count of the same money.
        $this->partialUnique(
            'pm_approval_requests',
            ['company_id', 'subject_type', 'subject_id'],
            'pm_approval_requests_live_unique',
            "deleted_at IS NULL AND status = 'pending'"
        );

        Schema::create('pm_approval_actions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('request_id');
            $t->unsignedBigInteger('step_id')->nullable();
            $t->unsignedSmallInteger('position');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('actor_name', 190)->nullable();     // kept so the record still reads after
                                                           // the account is deactivated
            $t->string('decision', 20);                    // approved | rejected | delegated | cancelled
            $t->text('comment')->nullable();
            $t->timestamp('acted_at')->useCurrent();
            $t->timestamps();

            $t->foreign('request_id')->references('id')->on('pm_approval_requests')->cascadeOnDelete();
            $t->index(['company_id', 'request_id', 'position']);
        });

        // One decision per person per step. Clicking approve twice must not satisfy a
        // two-signature rule on its own.
        $this->partialUnique(
            'pm_approval_actions',
            ['company_id', 'request_id', 'position', 'user_id'],
            'pm_approval_actions_once_unique',
            "decision in ('approved', 'rejected')"
        );

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function partialUnique(string $table, array $columns, string $name, string $where): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX ' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ') WHERE ' . $where);
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
