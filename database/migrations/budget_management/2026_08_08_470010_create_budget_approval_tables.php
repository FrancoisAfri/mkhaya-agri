<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable budget approval routing.
 *
 * What was here before was a single "Approve" button on the budget page: whoever opened the record
 * and held `budgets.approve` could activate two million rand of allocation, alone, with no record
 * of who else should have looked at it and no queue anybody could see. The same was true of
 * adjustments and overspend overrides — the two things that actually move money after a budget is
 * live.
 *
 * Companies do not agree on who signs off what, so the shape is designed rather than coded, in the
 * same four tables Project Management uses:
 *
 *   budget_approval_flows     one per (tenant, subject type). Optional threshold so a R500
 *                             adjustment does not cross three desks; optional narrowing to capex vs
 *                             opex or to a single category, because a company that reviews every
 *                             capital increase rarely reviews stationery the same way.
 *   budget_approval_steps     the ladder. Ordered; each step names *who* by rule, not by person.
 *   budget_approval_requests  one live request per submitted thing, carrying which step it is on.
 *   budget_approval_actions   every decision — who said yes, when, and what they said.
 *
 * `approver_type` is one of:
 *   role           a platform role (spatie) — "anyone with Finance Manager"
 *   user           a named person, for the company that genuinely wants one
 *   manager        the submitter's line manager, from HR when it is installed
 *   budget_owner   whoever owns the budget the request is about (budget_owners)
 *
 * Nothing here changes what happens on a tenant that never opens the designer: with no flow, a
 * request still queues, and whoever holds `budgets.approve` decides it — which is who could decide
 * it before. The difference is that it is now a queue somebody can find, with a notification and a
 * record behind it.
 */
return new class extends Migration
{
    private const TABLES = [
        'budget_approval_flows',
        'budget_approval_steps',
        'budget_approval_requests',
        'budget_approval_actions',
    ];

    public function up(): void
    {
        Schema::create('budget_approval_flows', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 120);
            $t->string('subject_type', 30);          // budget | adjustment | override | reservation
            $t->boolean('is_active')->default(true);
            // Below this, nothing is routed: it is approved on submission. Null = everything routes.
            $t->decimal('threshold_amount', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            // Optional narrowing. Null on both means the flow covers the whole tenant.
            $t->string('expenditure_type', 10)->nullable();          // capex | opex
            $t->unsignedBigInteger('budget_category_id')->nullable()->index();
            // What happens if nobody acts. Null = wait indefinitely, which is at least honest.
            $t->unsignedSmallInteger('escalate_after_hours')->nullable();
            $t->text('description')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'subject_type', 'is_active']);
        });

        Schema::create('budget_approval_steps', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('flow_id');
            $t->unsignedSmallInteger('position');    // 1-based; the ladder is climbed in order
            $t->string('name', 120);
            $t->string('approver_type', 20);         // role | user | manager | budget_owner
            $t->string('approver_ref', 120)->nullable();  // role name or user id
            $t->unsignedSmallInteger('min_approvals')->default(1);
            // Whether the person who submitted may approve their own step. Off by default: a
            // separation-of-duties rule has to be waived deliberately, not by accident.
            $t->boolean('allow_self_approval')->default(false);
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('flow_id')->references('id')->on('budget_approval_flows')->cascadeOnDelete();
            $t->index(['company_id', 'flow_id', 'position']);
        });

        Schema::create('budget_approval_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('flow_id')->nullable();
            $t->unsignedBigInteger('budget_id')->nullable()->index();
            $t->string('subject_type', 30);
            $t->unsignedBigInteger('subject_id');
            $t->uuid('subject_uuid')->nullable()->index();
            // Denormalised: the inbox lists things from four tables and must not join all four per
            // row. It is also what the thing was called when it was submitted, which is the honest
            // thing to show the person deciding.
            $t->string('subject_label', 190)->nullable();
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

            $t->foreign('flow_id')->references('id')->on('budget_approval_flows')->nullOnDelete();
            $t->index(['company_id', 'status', 'current_position']);
            $t->index(['company_id', 'subject_type', 'subject_id']);
        });

        // One live request per thing. A second pending request for the same adjustment is the same
        // money queued twice, and whichever is approved second applies it again.
        $this->partialUnique(
            'budget_approval_requests',
            ['company_id', 'subject_type', 'subject_id'],
            'budget_approval_requests_live_unique',
            "deleted_at IS NULL AND status = 'pending'"
        );

        Schema::create('budget_approval_actions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('request_id');
            $t->unsignedBigInteger('step_id')->nullable();
            $t->unsignedSmallInteger('position');
            $t->unsignedBigInteger('user_id')->nullable();
            // Kept so the record still reads after the account is deactivated.
            $t->string('actor_name', 190)->nullable();
            $t->string('decision', 20);                    // approved | rejected | cancelled
            $t->text('comment')->nullable();
            $t->timestamp('acted_at')->useCurrent();
            $t->timestamps();

            $t->foreign('request_id')->references('id')->on('budget_approval_requests')->cascadeOnDelete();
            $t->index(['company_id', 'request_id', 'position']);
        });

        // One decision per person per step. A two-signature rule that one person satisfies by
        // clicking twice is not a two-signature rule.
        $this->partialUnique(
            'budget_approval_actions',
            ['company_id', 'request_id', 'position', 'user_id'],
            'budget_approval_actions_once_unique',
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
