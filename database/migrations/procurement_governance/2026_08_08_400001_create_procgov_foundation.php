<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement Governance foundation.
 *
 * Three things live here, and everything else in the module hangs off them:
 *
 *  1. `procgov_settings` — one row per tenant. It carries the capability switches (§12: the module
 *     is a platform, and each capability inside it is separately purchasable/enableable), the
 *     reference prefixes, and the thresholds the rules engine and workload analytics read. Nothing
 *     regulatory is hard-coded — §5.3 is explicit that the same code must serve organisations under
 *     different frameworks, so every number a regulation would fix is a column here.
 *
 *  2. `procgov_records` — the Procurement 360° record (§6.1). PROC-2026-000145 is the spine: a
 *     request, a plan line, a tender, an evaluation, an award, a contract and any deviations all
 *     point at one of these. It is deliberately thin — it holds identity, ownership and stage, not
 *     copies of the data other modules own.
 *
 *  3. `procgov_record_links` — how the spine reaches everything else (§11: "a client should not be
 *     maintaining six different databases"). A link is a typed pointer at a row in another module,
 *     carrying enough denormalised label to render the 360 view when that module is switched off or
 *     the row has been archived. No foreign key: the target module may not be installed, and PM's
 *     rule applies here too — an absent integration degrades the view, it never blocks the workflow.
 *
 * Cross-module ids throughout this module are plain nullable bigints rather than constrained keys,
 * for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Per-tenant configuration + capability switches (§12) ─────────────────────────────────
        Schema::create('procgov_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Capability switches. The module entitlement gates the whole module; these gate each
            // capability inside it, so a tenant who bought procurement but not governance keeps
            // normal procurement and simply never sees the governance surfaces.
            $t->boolean('cap_planning')->default(true);            // §2  Procurement Planning
            $t->boolean('cap_tender_evaluation')->default(true);   // §1  Tender Review & Evaluation
            $t->boolean('cap_deviations')->default(true);          // §5  Deviation Management
            $t->boolean('cap_reporting')->default(true);           // §3  Procurement Reporting
            $t->boolean('cap_workload')->default(true);            // §4  Workload & Capacity
            $t->boolean('cap_governance')->default(true);          // §5.3/§6/§7 rules, 360, audit

            // Reference prefixes — tenants recognise their own numbering.
            $t->string('record_prefix', 12)->default('PROC');
            $t->string('review_prefix', 12)->default('TR');
            $t->string('plan_prefix', 12)->default('PP');
            $t->string('deviation_prefix', 12)->default('DEV');
            $t->string('committee_prefix', 12)->default('EC');

            // §2.1 financial year. Month the FY opens (1-12); SA public sector defaults to April.
            $t->unsignedTinyInteger('financial_year_start_month')->default(4);

            // §2.3 budget integration: warn at this share of the available budget, block above it
            // only when the tenant says so (some organisations plan deliberately over-subscribed).
            $t->unsignedTinyInteger('budget_warning_threshold_pct')->default(90);
            $t->boolean('block_plan_over_budget')->default(false);

            // §1.7 moderation: a spread wider than this between evaluators on one criterion is
            // flagged for the chairperson. Expressed in percentage points of the maximum score.
            $t->unsignedTinyInteger('moderation_variance_threshold')->default(20);

            // §1.5 evaluations become immutable on submit; reopening is an authorised act, and this
            // says whether it is available at all to this tenant.
            $t->boolean('allow_evaluation_reopen')->default(true);

            // §1.3 no scoring until the conflict-of-interest declaration is cleared.
            $t->boolean('require_coi_declaration')->default(true);

            // §4.2 capacity bands, in concurrently-active procurements per officer.
            $t->unsignedSmallInteger('workload_high_threshold')->default(10);
            $t->unsignedSmallInteger('workload_overload_threshold')->default(15);

            // §9 escalation: how long a pending action may sit before management is told.
            $t->unsignedSmallInteger('escalation_days')->default(5);
            $t->unsignedSmallInteger('evaluation_sla_days')->default(14);

            $t->string('currency', 3)->default('ZAR');

            $t->timestamps();
            $t->unique('company_id');
        });

        // ── Atomic reference numbering, per tenant / type / year ─────────────────────────────────
        // A max(id)+1 read is a race under concurrency and produces duplicate references, which for
        // an audited procurement number is a defect people notice. This is claimed under a row lock.
        Schema::create('procgov_reference_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->string('sequence_type', 40);       // record | review | plan | deviation | committee
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('next_value')->default(1);
            $t->timestamps();

            $t->unique(['company_id', 'sequence_type', 'year'], 'procgov_seq_unique');
        });

        // ── The Procurement 360° record (§6.1) ───────────────────────────────────────────────────
        Schema::create('procgov_records', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('reference', 40);                    // PROC-2026-000145
            $t->string('title');
            $t->text('description')->nullable();

            $t->string('category', 120)->nullable();        // procurement category
            $t->string('method', 40)->nullable();           // OPEN_TENDER | RFQ | LIMITED | SOLE_SOURCE | …

            // Department is a company unit (the platform's org structure), project and cost centre
            // are cross-module and stay unconstrained.
            $t->unsignedBigInteger('company_unit_id')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->string('cost_centre', 60)->nullable();

            $t->foreignId('officer_id')->nullable()->constrained('users')->nullOnDelete();

            $t->decimal('estimated_value', 18, 2)->nullable();
            $t->decimal('awarded_value', 18, 2)->nullable();
            $t->string('currency', 3)->default('ZAR');

            // Lifecycle stage — the §11 chain, one column, so §4.4 can time the transitions.
            // REQUEST → PLANNING → SOURCING → EVALUATION → RECOMMENDATION → APPROVAL → AWARD
            //         → CONTRACT → DELIVERY → CLOSED (or CANCELLED at any point).
            $t->string('stage', 24)->default('REQUEST');
            $t->string('status', 24)->default('ACTIVE');    // ACTIVE | ON_HOLD | CLOSED | CANCELLED

            $t->unsignedBigInteger('financial_year_id')->nullable();
            $t->string('financial_year_label', 20)->nullable();  // survives the Budget module absent

            $t->timestamp('opened_at')->nullable();
            $t->timestamp('target_completion_at')->nullable();
            $t->timestamp('closed_at')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'reference'], 'procgov_records_ref_unique');
            $t->index(['company_id', 'stage', 'status'], 'procgov_records_stage_idx');
            $t->index(['company_id', 'officer_id'], 'procgov_records_officer_idx');
            $t->index(['company_id', 'company_unit_id'], 'procgov_records_unit_idx');
            $t->index(['company_id', 'financial_year_id'], 'procgov_records_fy_idx');
        });

        // ── Typed pointers from the spine into every other module (§6.1, §11) ────────────────────
        Schema::create('procgov_record_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('record_id')->constrained('procgov_records')->cascadeOnDelete();

            // What the link means in the 360 view: request | plan_item | budget | tender |
            // evaluation | supplier | contract | purchase_order | invoice | deviation | document …
            $t->string('link_role', 40);

            // Where it lives. `source_module` is the code_name, so the view can say "Contract
            // Management is not installed" rather than rendering a dead link.
            $t->string('source_module', 60);
            $t->string('entity_type', 80);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->uuid('entity_uuid')->nullable();

            // Denormalised so the 360 view still reads correctly when the source is unavailable.
            $t->string('entity_reference', 80)->nullable();
            $t->string('label', 190)->nullable();
            $t->decimal('amount', 18, 2)->nullable();
            $t->string('state', 40)->nullable();
            $t->json('meta')->nullable();

            $t->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->index(['company_id', 'record_id', 'link_role'], 'procgov_links_role_idx');
            $t->index(['company_id', 'entity_type', 'entity_id'], 'procgov_links_entity_idx');
            $t->unique(
                ['record_id', 'link_role', 'entity_type', 'entity_id'],
                'procgov_links_unique'
            );
        });

        // ── Stage transitions — the raw material for §4.4 processing-time analytics ──────────────
        // Durations are stored at transition time rather than derived on read, because the same
        // query then serves a dashboard over years of history without a self-join per row.
        Schema::create('procgov_stage_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('record_id')->constrained('procgov_records')->cascadeOnDelete();

            $t->string('from_stage', 24)->nullable();
            $t->string('to_stage', 24);
            $t->timestamp('occurred_at');
            $t->unsignedBigInteger('seconds_in_from_stage')->nullable();

            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'record_id', 'occurred_at'], 'procgov_stage_record_idx');
            $t->index(['company_id', 'from_stage', 'to_stage'], 'procgov_stage_pair_idx');
        });

        $this->applyTenantRls([
            'procgov_settings',
            'procgov_reference_sequences',
            'procgov_records',
            'procgov_record_links',
            'procgov_stage_events',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_stage_events');
        Schema::dropIfExists('procgov_record_links');
        Schema::dropIfExists('procgov_records');
        Schema::dropIfExists('procgov_reference_sequences');
        Schema::dropIfExists('procgov_settings');
    }

    /**
     * Tenant isolation in the database, matching the platform standard: company_id defaults from
     * the app.company_id session variable and FORCE RLS binds even the table owner. A NULL session
     * variable (console, platform admin) disables the filter, which is how seeders and jobs work.
     */
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
