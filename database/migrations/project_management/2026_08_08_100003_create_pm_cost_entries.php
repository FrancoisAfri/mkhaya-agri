<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-labour cost, entered where the work is (EPIC-040, PM-1410…1412).
 *
 * Until now the only cost a person could enter in this module was an hour of somebody's time.
 * Everything else — materials, plant hire, a subcontractor's invoice — could only arrive as
 * evidence through the API, which means in practice it did not arrive at all, and the Delivery
 * page showed a budget against almost nothing. "Actual cost" that only ever counts labour is a
 * number that misleads whoever reads it.
 *
 * The lifecycle mirrors the timesheet on purpose, because the reasoning is identical:
 *
 *   draft/submitted   somebody's claim about money
 *   approved          a supervisor standing behind it — and only now does it become cost
 *   paid              the money has actually left
 *
 * On approval the entry emits `cost.incurred` (or `invoice.paid` when it is marked paid) into the
 * evidence ledger, which is what derivation already consumes for `actual_cost`. So a cost typed on
 * this screen and one posted by Accounting arrive at the same place, ranked by the same rules — no
 * second cost pipeline, no two totals to reconcile (PM-ADR-002, PM-ADR-010).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_cost_entries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            // Optional: a delivery charge belongs to the project, a concrete pour to a task.
            $t->unsignedBigInteger('task_id')->nullable()->index();
            $t->string('category', 30);              // material | plant | subcontractor | expense | travel | other
            $t->string('description', 255);
            $t->decimal('amount', 18, 2);            // PM-ADR-006: never a float
            $t->string('currency', 3);
            $t->date('incurred_on');
            $t->string('status', 20)->default('draft');   // draft | submitted | approved | rejected | paid
            // Supplier and the backing document, both through the existing seam so a tenant with
            // Procurement or Documents installed links rather than retypes.
            $t->unsignedBigInteger('supplier_ref_id')->nullable();
            $t->string('document_id', 190)->nullable();
            $t->string('invoice_number', 60)->nullable();
            $t->boolean('is_billable')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->text('rejection_reason')->nullable();
            // What the ExpenseSink gave back, so a PM-local expense can be traced after Accounting
            // is switched on and starts posting them for real.
            $t->string('posted_reference', 60)->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('supplier_ref_id')->references('id')->on('pm_external_refs')->nullOnDelete();
            $t->index(['company_id', 'project_id', 'status']);
            $t->index(['company_id', 'incurred_on']);
        });

        $this->applyTenantRls(['pm_cost_entries']);
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_cost_entries');
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
