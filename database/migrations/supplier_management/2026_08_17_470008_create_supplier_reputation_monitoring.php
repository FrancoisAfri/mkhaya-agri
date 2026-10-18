<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reputation monitoring: what the internet and the social platforms say about a supplier.
 *
 * Three tables, and the shape of each is decided by a specific way this feature fails elsewhere.
 *
 *  supplier_handles          What to search for. A supplier is not one string: it is a registered
 *                            name, a trading name, two or three brands, a website, and a handle per
 *                            platform — and the mentions that matter are usually against a brand
 *                            rather than the entity on the invoice.
 *
 *  supplier_findings         One row per mention, carrying its own entity-match confidence. The
 *                            failure mode this defends against is the one that kills the feature:
 *                            same name, different company. So a finding stores WHY it was attributed
 *                            to this supplier, and anything below the confidence floor lands in a
 *                            review queue instead of on the risk score.
 *
 *  supplier_monitoring_runs  Proof the watching happened, including when it found nothing. "We were
 *                            monitoring" is an assertion; a run record is evidence, and the difference
 *                            matters the day somebody asks why a supplier's collapse was a surprise.
 *
 * Findings are dated and never overwritten, so the timeline is a record rather than a snapshot, and
 * an adjudicated false positive stays adjudicated when the same article surfaces again next month.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What to watch. Handles are per platform because "abcholdings" on one is a different account
        // from "abcholdings" on another, and monitoring the wrong one is worse than monitoring none.
        Schema::create('supplier_handles', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            $t->string('kind', 20);                        // brand | website | handle | person
            $t->string('platform', 30)->nullable();        // linkedin | x | facebook | … ; null for brands
            $t->string('value', 250);                      // the brand name, domain, or @handle
            $t->unsignedBigInteger('person_id')->nullable();   // when the subject is a director/UBO

            $t->boolean('is_active')->default(true);
            $t->string('added_by_source', 20)->default('supplier');   // supplier | discovered | manual
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'supplier_id', 'is_active'], 'supplier_handles_active_idx');
        });

        Schema::create('supplier_findings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            // Where it came from.
            $t->string('channel', 20);                     // social | news | web | forum | regulatory
            $t->string('platform', 30)->nullable();        // linkedin | x | reddit | …
            $t->string('source_name', 150)->nullable();    // publication or account name
            $t->text('url')->nullable();
            $t->char('url_hash', 64)->nullable();          // dedupe key — the same article twice is one finding
            $t->string('external_id', 190)->nullable();
            $t->timestamp('published_at')->nullable();
            $t->string('author', 190)->nullable();

            // What it says.
            $t->string('title', 500)->nullable();
            $t->text('excerpt')->nullable();
            $t->string('language', 10)->nullable();

            // What the analysis made of it. `is_positive` exists because a supplier winning a large
            // contract is a finding worth showing and not a risk — a system that only records the bad
            // news reads as a charge sheet rather than a picture.
            $t->string('category', 40)->nullable();        // fraud | corruption | insolvency | positive | …
            $t->string('severity', 20)->nullable();        // none | low | medium | high | critical
            $t->string('sentiment', 20)->nullable();       // positive | neutral | negative
            $t->boolean('is_positive')->default(false);
            $t->unsignedTinyInteger('analysis_confidence')->nullable();
            $t->boolean('ai_generated')->default(true);    // the categorisation, not the article
            $t->text('analysis_note')->nullable();

            // Why we think this is about THIS supplier — the false-positive defence.
            $t->unsignedTinyInteger('entity_confidence')->default(0);
            $t->json('entity_basis')->nullable();
            $t->string('matched_subject', 250)->nullable();  // the brand, handle or person it matched

            // Corroboration: the same story from three outlets is stronger than from one.
            $t->unsignedSmallInteger('corroborations')->default(0);

            $t->string('status', 20)->default('open');     // open | confirmed | false_positive | dismissed | needs_review
            $t->unsignedBigInteger('adjudicated_by')->nullable();
            $t->timestamp('adjudicated_at')->nullable();
            $t->text('adjudication_note')->nullable();

            $t->unsignedBigInteger('monitoring_run_id')->nullable()->index();
            $t->string('provider_code', 60)->nullable();
            $t->timestamp('first_seen_at');
            $t->timestamp('last_seen_at');
            $t->timestamps();

            // One row per source per supplier. Without this the same article re-raises every month and
            // an adjudication somebody made is undone by the next run.
            $t->unique(['company_id', 'supplier_id', 'url_hash'], 'supplier_findings_source_unique');
            $t->index(['company_id', 'supplier_id', 'published_at'], 'supplier_findings_timeline_idx');
            $t->index(['company_id', 'status', 'severity'], 'supplier_findings_triage_idx');
        });

        Schema::create('supplier_monitoring_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            $t->string('scope', 30);                       // social | news | web | full
            $t->string('trigger', 30)->default('manual');  // manual | onboarding | scheduled | event
            $t->json('platforms')->nullable();             // which sources were actually asked
            $t->json('subjects')->nullable();              // what was searched for

            $t->unsignedSmallInteger('sources_queried')->default(0);
            $t->unsignedSmallInteger('sources_unavailable')->default(0);
            $t->unsignedSmallInteger('mentions_seen')->default(0);
            $t->unsignedSmallInteger('findings_new')->default(0);
            $t->unsignedSmallInteger('findings_updated')->default(0);
            $t->unsignedSmallInteger('below_confidence_floor')->default(0);
            $t->unsignedSmallInteger('alerts_raised')->default(0);

            $t->decimal('reputation_before', 6, 2)->nullable();
            $t->decimal('reputation_after', 6, 2)->nullable();

            $t->unsignedBigInteger('run_by')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('completed_at')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'supplier_id', 'started_at'], 'supplier_monitoring_runs_history_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['supplier_handles', 'supplier_findings', 'supplier_monitoring_runs'] as $table) {
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
        Schema::dropIfExists('supplier_monitoring_runs');
        Schema::dropIfExists('supplier_findings');
        Schema::dropIfExists('supplier_handles');
    }
};
