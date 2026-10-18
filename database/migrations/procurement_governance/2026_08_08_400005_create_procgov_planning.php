<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement Planning (§2) — the annual plan, its line items, and the transitions that move it.
 *
 * The plan header is the approval unit (a department's plan for a financial year is approved as a
 * whole), and the line item is the *planning* unit: each one is a procurement requirement with its
 * own money, dates, officer and status, and each one eventually becomes a live procurement. The
 * link from item to `procgov_records` is what makes §2.4's "planned vs in progress vs completed"
 * answerable without anyone re-keying anything.
 *
 * Money on a line item is stored in three columns rather than derived on read — estimated,
 * committed and actual. Committed and actual come from the Budget module when it is installed
 * (BudgetService already models reservations, commitments and consumption) and are refreshed by
 * the planning service. Storing them means the plan still renders honestly, marked as of a
 * timestamp, on a tenant who has no Budget module at all — which §11's integration philosophy
 * requires and §2.3 does not preclude.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procgov_plans', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('reference', 40);                  // PP-2026-0007
            $t->string('title');
            $t->text('description')->nullable();

            // §2.1 scope
            $t->unsignedBigInteger('financial_year_id')->nullable();
            $t->string('financial_year_label', 20);       // "2026/27" — always present
            $t->unsignedBigInteger('company_unit_id')->nullable();   // department
            $t->unsignedBigInteger('business_unit_id')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->string('cost_centre', 60)->nullable();
            $t->unsignedBigInteger('budget_id')->nullable();
            $t->string('category', 120)->nullable();

            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * §2.2 workflow. Kept as a status column with a service-enforced transition table
             * rather than a state machine package, matching how the rest of the platform models
             * workflow:
             *   DRAFT → DEPARTMENT_REVIEW → BUDGET_VERIFICATION → PROCUREMENT_REVIEW
             *         → MANAGEMENT_APPROVAL → APPROVED → IN_PROGRESS → COMPLETED
             *   CANCELLED is reachable from anything before COMPLETED.
             */
            $t->string('status', 30)->default('DRAFT');

            $t->string('currency', 3)->default('ZAR');
            $t->decimal('total_estimated_value', 18, 2)->default(0);
            $t->decimal('total_committed_value', 18, 2)->default(0);
            $t->decimal('total_actual_value', 18, 2)->default(0);
            $t->decimal('budget_available_at_verification', 18, 2)->nullable();
            $t->timestamp('financials_refreshed_at')->nullable();

            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancellation_reason')->nullable();

            $t->unsignedBigInteger('approval_request_id')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'reference'], 'procgov_plans_ref_unique');
            $t->index(['company_id', 'financial_year_label', 'status'], 'procgov_plans_fy_idx');
            $t->index(['company_id', 'company_unit_id'], 'procgov_plans_unit_idx');
        });

        Schema::create('procgov_plan_items', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained('procgov_plans')->cascadeOnDelete();

            $t->string('reference', 40);                 // procurement reference within the plan
            $t->string('description');
            $t->text('business_justification')->nullable();
            $t->string('category', 120)->nullable();

            $t->decimal('estimated_value', 18, 2)->default(0);
            $t->string('currency', 3)->default('ZAR');
            $t->unsignedBigInteger('budget_id')->nullable();
            $t->decimal('budget_allocation', 18, 2)->nullable();

            // Refreshed from the Budget module; `null` means "not known", which the UI renders as
            // such rather than as zero.
            $t->decimal('committed_amount', 18, 2)->nullable();
            $t->decimal('actual_spend', 18, 2)->nullable();
            $t->unsignedBigInteger('budget_reservation_id')->nullable();

            // OPEN_TENDER | RFQ | LIMITED_BID | SOLE_SOURCE | PANEL | TRANSVERSAL | PETTY
            $t->string('procurement_method', 40)->nullable();

            $t->date('planned_start_date')->nullable();
            $t->date('planned_tender_date')->nullable();
            $t->date('expected_award_date')->nullable();
            $t->date('expected_contract_start')->nullable();
            $t->date('expected_contract_end')->nullable();

            // Actuals, so §3.4 cycle time and §2.4 "delayed" are measured rather than asserted.
            $t->date('actual_start_date')->nullable();
            $t->date('actual_tender_date')->nullable();
            $t->date('actual_award_date')->nullable();

            $t->foreignId('responsible_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('company_unit_id')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();

            $t->string('priority', 12)->default('MEDIUM');    // LOW | MEDIUM | HIGH | CRITICAL

            // PLANNED | APPROVED | IN_PROGRESS | TENDERED | AWARDED | COMPLETED | DELAYED | CANCELLED
            $t->string('status', 20)->default('PLANNED');

            // The 360 record this line became, once it is live.
            $t->unsignedBigInteger('record_id')->nullable();

            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->text('notes')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'reference'], 'procgov_plan_items_ref_unique');
            $t->index(['company_id', 'plan_id', 'status'], 'procgov_plan_items_status_idx');
            $t->index(['company_id', 'responsible_officer_id'], 'procgov_plan_items_officer_idx');
            $t->index(['company_id', 'planned_tender_date'], 'procgov_plan_items_date_idx');
            $t->index(['company_id', 'record_id'], 'procgov_plan_items_record_idx');
        });

        // §2.2: "every transition must be logged". The audit ledger records it too, but a plan's
        // own history is read constantly (it renders on the plan page) and should not require a
        // scan of the ledger.
        Schema::create('procgov_plan_transitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained('procgov_plans')->cascadeOnDelete();

            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30);
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_label', 190)->nullable();
            $t->text('comment')->nullable();
            $t->unsignedBigInteger('seconds_in_from_status')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();

            $t->index(['company_id', 'plan_id', 'occurred_at'], 'procgov_plan_trans_idx');
        });

        $this->applyTenantRls([
            'procgov_plans',
            'procgov_plan_items',
            'procgov_plan_transitions',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_plan_transitions');
        Schema::dropIfExists('procgov_plan_items');
        Schema::dropIfExists('procgov_plans');
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
