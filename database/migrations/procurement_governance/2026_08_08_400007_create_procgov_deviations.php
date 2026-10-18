<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deviation Management (§5).
 *
 * A deviation is the request to depart from the normal procurement route — an emergency, a sole
 * supplier, an expansion of an existing contract. It is the single highest-risk transaction in
 * procurement and the one auditors go to first, which is why §5 asks for the full apparatus:
 * digital request, configurable approval chain, compliance checks, documents, notifications, audit
 * trail and reporting.
 *
 * The workflow status column here is the *stage*; the actual approvers come from a chain selected
 * by the rules engine (§5.2 — by value, department, category, deviation type and risk level), so
 * this table never encodes who signs what. The compliance result (§5.4) is a foreign key to a
 * `procgov_compliance_checks` row rather than a copied PASS/WARNING/FAIL, so the individual rule
 * outcomes that produced the verdict stay reachable from the deviation forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Deviation types are tenant-configured: "emergency", "sole supplier", "contract expansion",
        // "deviation from SCM policy" mean different things under different frameworks, and each
        // can require its own documents, justification and approval chain.
        Schema::create('procgov_deviation_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->string('code', 40);
            $t->string('name');
            $t->text('description')->nullable();

            $t->string('default_risk_level', 12)->default('MEDIUM');  // LOW | MEDIUM | HIGH | CRITICAL
            $t->string('approval_chain_code', 40)->nullable();        // overrides chain selection
            $t->boolean('requires_supplier')->default(false);
            $t->boolean('requires_risk_assessment')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();

            $t->unique(['company_id', 'code'], 'procgov_dev_type_unique');
        });

        Schema::create('procgov_deviations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('reference', 40);                 // DEV-2026-0042
            $t->string('title');

            // §5.1 identification
            $t->unsignedBigInteger('record_id')->nullable();
            $t->string('procurement_reference', 80)->nullable();
            $t->unsignedBigInteger('company_unit_id')->nullable();
            $t->foreignId('requesting_officer_id')->nullable()->constrained('users')->nullOnDelete();

            $t->decimal('procurement_value', 18, 2)->nullable();
            $t->string('currency', 3)->default('ZAR');

            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('supplier_name', 190)->nullable();

            $t->unsignedBigInteger('deviation_type_id')->nullable();
            $t->string('deviation_type_code', 40)->nullable();

            // §5.1 narrative — separate columns rather than one blob, because §5.4 checks each for
            // presence and length and an auditor reads them individually.
            $t->text('reason')->nullable();
            $t->text('business_justification')->nullable();
            $t->text('circumstances')->nullable();
            $t->text('risk_assessment')->nullable();
            $t->string('risk_level', 12)->default('MEDIUM');
            $t->text('proposed_alternative')->nullable();
            $t->text('financial_impact')->nullable();
            $t->decimal('financial_impact_amount', 18, 2)->nullable();

            $t->date('requested_approval_date')->nullable();

            /*
             * §5.2 workflow:
             *   DRAFT → SUBMITTED → COMPLIANCE_REVIEW → PROCUREMENT_REVIEW → FINANCIAL_REVIEW
             *         → MANAGEMENT_APPROVAL → APPROVED | REJECTED → CLOSED
             * The intermediate review stages are driven by the selected chain's steps, so a tenant
             * whose chain has three steps never sees the two it does not use.
             */
            $t->string('status', 30)->default('DRAFT');

            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->unsignedBigInteger('rule_set_id')->nullable();
            $t->unsignedBigInteger('compliance_check_id')->nullable();
            $t->string('compliance_result', 12)->nullable();   // PASS | WARNING | FAIL, last run

            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('decision', 20)->nullable();            // APPROVED | REJECTED
            $t->text('decision_comment')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('withdrawn_at')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'reference'], 'procgov_dev_ref_unique');
            $t->index(['company_id', 'status'], 'procgov_dev_status_idx');
            $t->index(['company_id', 'company_unit_id'], 'procgov_dev_unit_idx');
            $t->index(['company_id', 'requesting_officer_id'], 'procgov_dev_officer_idx');
            $t->index(['company_id', 'record_id'], 'procgov_dev_record_idx');
            $t->index(['company_id', 'submitted_at'], 'procgov_dev_submitted_idx');
        });

        Schema::create('procgov_deviation_transitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('deviation_id')->constrained('procgov_deviations')->cascadeOnDelete();

            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30);
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_label', 190)->nullable();
            $t->text('comment')->nullable();
            $t->unsignedBigInteger('seconds_in_from_status')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();

            $t->index(['company_id', 'deviation_id', 'occurred_at'], 'procgov_dev_trans_idx');
        });

        $this->applyTenantRls([
            'procgov_deviation_types',
            'procgov_deviations',
            'procgov_deviation_transitions',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_deviation_transitions');
        Schema::dropIfExists('procgov_deviations');
        Schema::dropIfExists('procgov_deviation_types');
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
