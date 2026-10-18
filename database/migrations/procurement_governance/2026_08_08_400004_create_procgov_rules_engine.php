<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The configurable Procurement Rules Engine (§5.3) and the approval chains it selects (§5.2).
 *
 * §5.3 is the most important architectural instruction in the brief: do not hard-code one
 * regulatory framework. A South African public entity under the MFMA, a private company under its
 * own delegation of authority, and a donor-funded programme under grant conditions all need
 * "required documents, approval levels, financial thresholds, mandatory fields, required
 * declarations, time limits, compliance checks, escalation rules" — the same eight things with
 * different values. So the framework is data: a tenant configures a rule set, and the engine that
 * evaluates it never learns any regulation's name.
 *
 * Rule sets are versioned and freezable. A compliance check records *which version* it ran, because
 * "this deviation passed compliance" is only meaningful alongside the rules that were in force when
 * it did — an auditor two years later is asking exactly that question.
 *
 * Approval chains are selected the same way: a chain declares the conditions under which it
 * applies (value band, department, category, deviation type, risk level) and the engine picks the
 * most specific match. That is how one deployment supports "deviations above R1m go to the
 * accounting officer, below that to the manager" without a line of code.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Rule sets: a named, versioned framework ──────────────────────────────────────────────
        Schema::create('procgov_rule_sets', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('code', 40);
            $t->string('name');
            $t->text('description')->nullable();

            // Free text on purpose — the engine must never branch on this value.
            $t->string('framework_label', 120)->nullable();   // "MFMA / SCM Regulations", "Group DOA" …

            // Which subject this set governs: record | plan | plan_item | tender_review |
            // evaluation | deviation | any
            $t->string('applies_to', 30)->default('any');

            $t->unsignedSmallInteger('version')->default(1);
            $t->boolean('is_active')->default(true);

            // Frozen sets cannot be edited — a new version is created instead, so a historical
            // check can always be replayed against the rules it actually ran.
            $t->boolean('is_frozen')->default(false);
            $t->timestamp('frozen_at')->nullable();
            $t->foreignId('frozen_by')->nullable()->constrained('users')->nullOnDelete();

            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'code', 'version'], 'procgov_ruleset_unique');
            $t->index(['company_id', 'applies_to', 'is_active'], 'procgov_ruleset_applies_idx');
        });

        Schema::create('procgov_rules', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('rule_set_id')->constrained('procgov_rule_sets')->cascadeOnDelete();

            $t->string('code', 60);
            $t->string('name');

            /*
             * The eight configurable kinds from §5.3, plus the checks §5.4 runs before submission.
             * Each is evaluated by a small handler keyed on this string, so adding a kind is one
             * class, never a change to the caller:
             *
             *   REQUIRED_DOCUMENT     parameters: {category_codes:[], min_count, must_be_current}
             *   REQUIRED_FIELD        parameters: {fields:[]}
             *   REQUIRED_DECLARATION  parameters: {declaration:'coi'|'confidentiality', of:'committee'}
             *   REQUIRED_APPROVAL     parameters: {chain_code} — the approval level that must exist
             *   FINANCIAL_THRESHOLD   parameters: {min, max, method_required:[]}
             *   TIME_LIMIT            parameters: {from_field, to_field, max_days}
             *   BUDGET_AVAILABILITY   parameters: {allow_over_commit:false}
             *   SUPPLIER_COMPLIANCE   parameters: {require_tax_clearance, require_bbbee, max_risk}
             *   MANDATORY_JUSTIFICATION parameters: {min_length}
             *   CUSTOM                parameters: {expression}
             */
            $t->string('rule_type', 40);

            // FAIL blocks submission, WARNING annotates it, INFO is advisory only (§5.4 result).
            $t->string('severity', 12)->default('FAIL');

            // Narrows the rule inside its set: only above R500k, only for a category, only for
            // deviations of a given type. Empty means "always, within this set".
            //   {min_value, max_value, categories:[], company_unit_ids:[], methods:[],
            //    deviation_types:[], risk_levels:[]}
            $t->json('conditions')->nullable();
            $t->json('parameters')->nullable();

            $t->string('message', 500)->nullable();      // shown to the user when it does not pass
            $t->string('remedy', 500)->nullable();       // what to do about it

            // §5.3 escalation rules: unresolved for this long → tell someone.
            $t->unsignedSmallInteger('escalate_after_days')->nullable();
            $t->string('escalate_to_type', 20)->nullable();   // role | user | unit_manager
            $t->unsignedBigInteger('escalate_to_id')->nullable();

            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['rule_set_id', 'code'], 'procgov_rule_unique');
            $t->index(['company_id', 'rule_type', 'is_active'], 'procgov_rule_type_idx');
        });

        // ── A single evaluation of a subject against a rule set (§5.4) ───────────────────────────
        Schema::create('procgov_compliance_checks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('subject_type', 80);              // procgov_deviations, procgov_plans, …
            $t->unsignedBigInteger('subject_id');
            $t->string('subject_reference', 80)->nullable();
            $t->unsignedBigInteger('record_id')->nullable();

            $t->unsignedBigInteger('rule_set_id')->nullable();
            $t->string('rule_set_code', 40)->nullable();
            $t->unsignedSmallInteger('rule_set_version')->nullable();

            // PASS | WARNING | FAIL — the §5.4 headline.
            $t->string('result', 12);
            $t->unsignedSmallInteger('rules_evaluated')->default(0);
            $t->unsignedSmallInteger('failures')->default(0);
            $t->unsignedSmallInteger('warnings')->default(0);

            // What the subject looked like when it was checked, so the outcome is reproducible.
            $t->json('context')->nullable();

            $t->string('trigger', 30)->default('manual'); // manual | pre_submit | scheduled | transition
            $t->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('checked_at');
            $t->timestamps();

            $t->index(['company_id', 'subject_type', 'subject_id'], 'procgov_check_subject_idx');
            $t->index(['company_id', 'result', 'checked_at'], 'procgov_check_result_idx');
        });

        Schema::create('procgov_compliance_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('check_id')->constrained('procgov_compliance_checks')->cascadeOnDelete();

            $t->unsignedBigInteger('rule_id')->nullable();  // nullable: the rule may later be deleted
            $t->string('rule_code', 60);
            $t->string('rule_name', 190)->nullable();
            $t->string('rule_type', 40);
            $t->string('severity', 12);

            $t->string('outcome', 12);                      // PASS | WARNING | FAIL | SKIPPED
            $t->string('message', 500)->nullable();
            $t->json('detail')->nullable();                 // which document was missing, by how much …

            // Set when someone accepts a warning knowingly — §5.4 allows proceeding on WARNING,
            // and who accepted it is exactly the sort of thing an audit asks about later.
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->text('acknowledgement_reason')->nullable();

            $t->timestamps();

            $t->index(['company_id', 'check_id', 'outcome'], 'procgov_result_outcome_idx');
        });

        // ── Configurable approval chains (§5.2) ──────────────────────────────────────────────────
        Schema::create('procgov_approval_chains', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('code', 40);
            $t->string('name');
            $t->text('description')->nullable();

            // deviation | plan | tender_recommendation | record | evaluation_reopen | criteria_change
            $t->string('applies_to', 40);

            // Selection conditions, same vocabulary as a rule's. The most specific active chain
            // whose conditions match wins; `specificity` breaks ties deterministically so two
            // equally-matching chains cannot silently swap between requests.
            $t->json('conditions')->nullable();
            $t->unsignedSmallInteger('specificity')->default(0);

            $t->boolean('is_active')->default(true);
            $t->boolean('is_default')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'code'], 'procgov_chain_unique');
            $t->index(['company_id', 'applies_to', 'is_active'], 'procgov_chain_applies_idx');
        });

        Schema::create('procgov_approval_chain_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('chain_id')->constrained('procgov_approval_chains')->cascadeOnDelete();

            $t->unsignedSmallInteger('step_no');
            $t->string('name', 120);                      // "Compliance Review", "Financial Review" …

            // role | user | permission | unit_manager | budget_owner | committee_chair
            $t->string('approver_type', 24);
            $t->unsignedBigInteger('approver_id')->nullable();
            $t->string('approver_ref', 190)->nullable();   // role name or permission name

            // A step may need more than one of a group to sign (a committee decision).
            $t->unsignedSmallInteger('quorum')->default(1);

            $t->unsignedSmallInteger('sla_hours')->nullable();
            $t->string('escalate_to_type', 24)->nullable();
            $t->unsignedBigInteger('escalate_to_id')->nullable();

            $t->boolean('is_mandatory')->default(true);
            $t->boolean('can_delegate')->default(true);

            // Skip this step unless its own conditions match — lets one chain cover a value band
            // where only the top slice needs an executive signature.
            $t->json('conditions')->nullable();

            $t->timestamps();

            $t->unique(['chain_id', 'step_no'], 'procgov_chain_step_unique');
        });

        // ── A running approval (§5.2, and reused by planning §2.2 and recommendations §1.8) ──────
        Schema::create('procgov_approval_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->unsignedBigInteger('chain_id')->nullable();
            $t->string('chain_code', 40)->nullable();

            $t->string('subject_type', 80);
            $t->unsignedBigInteger('subject_id');
            $t->string('subject_reference', 80)->nullable();
            $t->string('subject_label', 190)->nullable();
            $t->unsignedBigInteger('record_id')->nullable();

            $t->decimal('amount', 18, 2)->nullable();

            // PENDING | APPROVED | REJECTED | WITHDRAWN | CANCELLED
            $t->string('status', 20)->default('PENDING');
            $t->unsignedSmallInteger('current_step')->default(1);
            $t->unsignedSmallInteger('total_steps')->default(0);

            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('requested_at');
            $t->timestamp('due_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('outcome_comment')->nullable();

            $t->timestamps();

            $t->index(['company_id', 'subject_type', 'subject_id'], 'procgov_appr_subject_idx');
            $t->index(['company_id', 'status', 'due_at'], 'procgov_appr_status_idx');
            $t->index(['company_id', 'record_id'], 'procgov_appr_record_idx');
        });

        Schema::create('procgov_approval_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('request_id')->constrained('procgov_approval_requests')->cascadeOnDelete();

            $t->unsignedSmallInteger('step_no');
            $t->string('name', 120);
            $t->string('approver_type', 24);
            $t->unsignedBigInteger('approver_id')->nullable();
            $t->string('approver_ref', 190)->nullable();
            $t->string('approver_label', 190)->nullable();

            $t->unsignedSmallInteger('quorum')->default(1);
            $t->unsignedSmallInteger('approvals_received')->default(0);

            // PENDING | ACTIVE | APPROVED | REJECTED | SKIPPED | ESCALATED
            $t->string('status', 20)->default('PENDING');

            $t->timestamp('activated_at')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('decision', 20)->nullable();
            $t->text('comment')->nullable();

            $t->timestamp('escalated_at')->nullable();
            $t->foreignId('escalated_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('delegated_from')->nullable()->constrained('users')->nullOnDelete();

            $t->timestamps();

            $t->unique(['request_id', 'step_no'], 'procgov_appr_step_unique');
            $t->index(['company_id', 'status', 'due_at'], 'procgov_appr_step_status_idx');
            $t->index(['company_id', 'approver_id', 'status'], 'procgov_appr_step_approver_idx');
        });

        $this->applyTenantRls([
            'procgov_rule_sets',
            'procgov_rules',
            'procgov_compliance_checks',
            'procgov_compliance_results',
            'procgov_approval_chains',
            'procgov_approval_chain_steps',
            'procgov_approval_requests',
            'procgov_approval_steps',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_approval_steps');
        Schema::dropIfExists('procgov_approval_requests');
        Schema::dropIfExists('procgov_approval_chain_steps');
        Schema::dropIfExists('procgov_approval_chains');
        Schema::dropIfExists('procgov_compliance_results');
        Schema::dropIfExists('procgov_compliance_checks');
        Schema::dropIfExists('procgov_rules');
        Schema::dropIfExists('procgov_rule_sets');
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
