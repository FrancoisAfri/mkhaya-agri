<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — assess: is this supplier safe to do business with?
 *
 * Layer 1 established who they are. This is the layer that turns those observations into a number
 * somebody can act on, and it needs three things the module does not have:
 *
 *  supplier_risk_scores    a snapshot per assessment — total, band, and the per-component breakdown
 *                          WITH the weights it used. Storing the weights is what makes an old score
 *                          reproducible after somebody retunes the model; without them, last
 *                          quarter's 63 cannot be explained, only asserted.
 *
 *  supplier_credit_reports dated bureau pulls. Deterioration is a diff between two reports, so one
 *                          row that gets overwritten each month can tell you a supplier is at 42 but
 *                          never that they were at 68 in March.
 *
 *  screening_hits          one row per (subject, list, matched name), carrying its adjudication.
 *                          This is the table that decides whether continuous screening is usable:
 *                          if a hit somebody cleared in January comes back every month, month two
 *                          produces the same forty alerts as month one and the feature gets
 *                          switched off. The unique key is what makes "resolved" stay resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_risk_scores', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            $t->decimal('total', 6, 2);                    // 0-100, higher is riskier
            $t->string('band', 20);                        // excellent | low | moderate | high | critical
            $t->unsignedTinyInteger('tier')->nullable();   // the diligence tier this was scored against

            // The breakdown, and the weights it was computed with. Both, always.
            $t->json('components');
            $t->json('weights');

            // What the score means procedurally, captured at the time: could this supplier have been
            // auto-approved, and what forced enhanced diligence.
            $t->boolean('auto_approvable')->default(false);
            $t->json('blockers')->nullable();
            $t->json('edd_triggers')->nullable();

            $t->decimal('previous_total', 6, 2)->nullable();
            $t->string('trigger', 40)->default('manual');  // manual | onboarding | monitoring | event
            $t->unsignedBigInteger('calculated_by')->nullable();
            $t->timestamp('calculated_at');
            $t->timestamps();

            $t->index(['company_id', 'supplier_id', 'calculated_at'], 'supplier_risk_scores_current_idx');
        });

        Schema::create('supplier_credit_reports', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            $t->string('provider_code', 60);
            $t->unsignedSmallInteger('score')->nullable();      // the bureau's own scale
            $t->unsignedSmallInteger('score_max')->default(100);
            $t->string('risk_band', 30)->nullable();            // whatever the bureau calls it
            $t->string('payment_behaviour', 40)->nullable();    // on_time | slow | delinquent

            $t->unsignedSmallInteger('defaults_count')->default(0);
            $t->unsignedSmallInteger('judgments_count')->default(0);
            $t->decimal('judgments_value', 14, 2)->nullable();
            $t->unsignedSmallInteger('collections_count')->default(0);
            $t->boolean('insolvency')->default(false);
            $t->boolean('business_rescue')->default(false);

            $t->decimal('recommended_limit', 14, 2)->nullable();
            $t->decimal('current_exposure', 14, 2)->nullable();

            $t->date('report_date');
            $t->json('raw')->nullable();
            $t->unsignedBigInteger('verification_id')->nullable();  // the observation this came in on
            $t->decimal('cost', 10, 2)->nullable();
            $t->timestamps();

            $t->index(['company_id', 'supplier_id', 'report_date'], 'supplier_credit_reports_history_idx');
        });

        Schema::create('screening_hits', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            // Who was screened.
            $t->string('subject_type', 20);                 // supplier | person
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('subject_name', 250);

            // What came back.
            $t->string('list_type', 20);                    // sanctions | pep | adverse_media | watchlist
            $t->string('list_name', 150)->nullable();       // FIC TFS, UN 1267, …
            $t->string('matched_name', 250);
            $t->string('external_ref', 120)->nullable();     // the provider's id for this entry

            // Why it matched — kept so an adjudicator can see what the machine was reasoning from
            // rather than being asked to trust a number.
            $t->unsignedTinyInteger('match_score');          // 0-100
            $t->json('match_basis')->nullable();             // name | dob | nationality | identifier …
            $t->json('details')->nullable();                 // positions, designations, dates

            $t->string('provider_code', 60);

            // The adjudication, which survives every later run.
            $t->string('status', 20)->default('open');       // open | confirmed | false_positive | dismissed
            $t->unsignedBigInteger('adjudicated_by')->nullable();
            $t->timestamp('adjudicated_at')->nullable();
            $t->text('adjudication_note')->nullable();

            $t->timestamp('first_seen_at');
            $t->timestamp('last_seen_at');
            $t->unsignedSmallInteger('times_seen')->default(1);
            $t->timestamps();

            // One row per subject per list entry. This is the constraint the whole feature rests on:
            // without it a monthly re-screen re-raises everything that was ever cleared.
            $t->unique(
                ['company_id', 'supplier_id', 'subject_type', 'subject_id', 'list_type', 'matched_name'],
                'screening_hits_subject_entry_unique'
            );
            $t->index(['company_id', 'status', 'list_type'], 'screening_hits_open_idx');
        });

        // Per-tenant risk model. A bank and a municipality do not weigh reputation the same way, and
        // a model nobody can retune is one that gets worked around instead of used.
        Schema::table('supplier_settings', function (Blueprint $t) {
            $t->json('risk_weights')->nullable()->after('require_tax_verification');
            $t->json('risk_bands')->nullable()->after('risk_weights');
            $t->decimal('auto_approve_below', 5, 2)->nullable()->after('risk_bands');
        });

        // Denormalised onto the supplier for the directory: filtering and sorting a list of two
        // thousand suppliers by their newest snapshot is a query nobody should have to write twice.
        // The snapshots remain authoritative — these are a cache of the latest one.
        Schema::table('suppliers', function (Blueprint $t) {
            $t->decimal('risk_score', 6, 2)->nullable()->after('segment');
            $t->string('risk_band', 20)->nullable()->after('risk_score');
            $t->unsignedTinyInteger('risk_tier')->nullable()->after('risk_band');
            $t->json('tier_factors')->nullable()->after('risk_tier');
            $t->timestamp('risk_calculated_at')->nullable()->after('tier_factors');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['supplier_risk_scores', 'supplier_credit_reports', 'screening_hits'] as $table) {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropColumn(['risk_score', 'risk_band', 'risk_tier', 'tier_factors', 'risk_calculated_at']);
        });

        Schema::table('supplier_settings', function (Blueprint $t) {
            $t->dropColumn(['risk_weights', 'risk_bands', 'auto_approve_below']);
        });

        Schema::dropIfExists('screening_hits');
        Schema::dropIfExists('supplier_credit_reports');
        Schema::dropIfExists('supplier_risk_scores');
    }
};
