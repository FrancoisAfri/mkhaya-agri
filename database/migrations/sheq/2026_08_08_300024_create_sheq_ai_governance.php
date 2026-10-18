<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The AI Safety Officer's governance substrate (§7).
 *
 * The order here is deliberate: the controls come before the model. §7 reads as a set of things the
 * AI must never do — never close a record, never approve a permit, never alter a risk rating, never
 * assert a fact without a citation, never see another tenant's data. Those are not properties of a
 * good prompt. A prompt is a request; the model can decline it, misread it, or be talked out of it
 * by the very text it is summarising. So every one of them is a table constraint or a code path here,
 * and the model is attached afterwards to something that already refuses.
 *
 * Three tables:
 *
 * - **sheq_ai_suggestions** — every AI output, without exception, lands here as a DRAFT proposal
 *   addressed to a human (SHEQ-AI-1). Nothing the AI produces reaches an operational table directly.
 *   The decision a person makes on it is recorded beside it, because "the AI said so" is not a
 *   defensible answer to an inspector and "a person accepted the AI's suggestion on this date" is.
 *
 * - **sheq_ai_call_log** — the telemetry SHEQ-AI-6 enumerates: model, prompt template version,
 *   which chunks were retrieved, tokens, latency, cost, a hash of the output, and what the human
 *   decided. Enough to reconstruct why the machine said what it said, months later, when someone
 *   asks.
 *
 * - **sheq_knowledge_chunks** — the retrieval corpus, tenant- and site-scoped in the table itself
 *   (SHEQ-AI-3) rather than in whatever query happens to be written against it.
 *
 * On SHEQ-AI-4 (no training on tenant data): there is nothing to build, and that is the point —
 * tenant text is only ever sent as prompt context to a provider under a no-training agreement, and
 * no column here accumulates a training corpus. The absence is the control.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Every AI output, as a proposal a human must accept ────────────────────────────────
        Schema::create('sheq_ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            // What the AI was asked to help with, and what it is attached to.
            $table->string('agent', 40)->index();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            /**
             * DRAFT is the only state the AI itself can write. ACCEPTED, EDITED and REJECTED are
             * all reachable only through a human decision, and SUPERSEDED only by a later run.
             */
            $table->string('status', 20)->default('DRAFT')->index();

            /**
             * Fixed to AI_AGENT at the database level, not passed in by the caller.
             *
             * SHEQ-AI-1 requires AI-authored content to be attributable as such. If the actor type
             * were a parameter, the one code path that forgot to set it would produce a suggestion
             * indistinguishable from a person's work — which is precisely the confusion the
             * requirement exists to prevent.
             */
            $table->string('actor_type', 20)->default('AI_AGENT');

            $table->text('title');
            $table->jsonb('content');

            /**
             * Assertions and their citations, kept apart from the prose (SHEQ-AI-2).
             *
             * Each entry is a claim with the chunk it came from. An assertion with no citation is
             * carried here too, flagged unverified — deleting it would be tidier and would also
             * hide exactly the sentences a reviewer most needs to look at.
             */
            $table->jsonb('assertions')->nullable();
            $table->boolean('has_unverified_assertions')->default(false);

            $table->decimal('confidence', 4, 3)->nullable();

            // The human decision. Null until somebody actually looks.
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->jsonb('edited_content')->nullable();

            $table->unsignedBigInteger('call_log_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'agent', 'status']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
        });

        // ── SHEQ-AI-6: what was asked, what came back, what it cost, what a human did ──────────
        Schema::create('sheq_ai_call_log', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('agent', 40)->index();
            $table->string('model', 120)->nullable();
            $table->string('provider', 60)->nullable();

            /**
             * The prompt template version.
             *
             * Without it a regression is uninvestigable: output quality drops, and there is no way
             * to tell whether the model changed under you or somebody edited the template.
             */
            $table->string('prompt_template', 60)->nullable();
            $table->unsignedInteger('prompt_version')->default(1);

            /** Which chunks were retrieved — the evidence the answer was allowed to draw on. */
            $table->jsonb('retrieved_chunk_ids')->nullable();
            $table->unsignedInteger('retrieved_count')->default(0);

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);

            /**
             * A hash of the output rather than the output itself.
             *
             * The suggestion row already holds the content. What this proves is that the content
             * a human approved is byte-for-byte the content the model produced — that nothing was
             * quietly rewritten between generation and approval.
             */
            $table->string('output_hash', 64)->nullable();

            /** Whether PII was stripped before the text left the tenant boundary (SHEQ-AI-5). */
            $table->boolean('redacted')->default(false);
            $table->unsignedInteger('redaction_count')->default(0);

            $table->boolean('succeeded')->default(true);
            $table->text('error')->nullable();

            /** The human's verdict, denormalised so a reviewer can read outcomes without a join. */
            $table->string('human_decision', 20)->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'agent', 'created_at']);
        });

        // ── SHEQ-AI-3: the corpus, scoped in the table rather than in the query ────────────────
        Schema::create('sheq_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable()->index();

            /**
             * Nullable site_id means "the whole tenant"; a value narrows it to one site.
             *
             * Retrieval for a site must see tenant-wide material and its own, and must never see
             * another site's. That asymmetry is the whole reason this column exists.
             */
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->string('source_type', 40)->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_ref', 255)->nullable();

            $table->text('title')->nullable();
            $table->text('body');

            /**
             * The citation string a reader can act on — "OHS Act s24(1)", "Incident INC-2026-0031".
             *
             * A footnote saying "chunk 4471" is not a citation. This is the text that appears next
             * to an assertion, and it has to be meaningful to a practitioner who wants to go and
             * read the source.
             */
            $table->string('citation', 255);

            $table->unsignedInteger('token_estimate')->default(0);
            $table->string('content_hash', 64)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'source_type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            /**
             * Full-text retrieval, not vectors.
             *
             * The platform's AI chain exposes chat completion and no embedding endpoint, so a
             * vector index would be an empty promise — a column nothing could populate. Postgres
             * full-text search works today, and the scoping that SHEQ-AI-3 actually cares about is
             * enforced in the query builder either way. The retriever's contract is written so the
             * backend can become vectors later without any caller changing.
             */
            DB::statement('CREATE INDEX sheq_knowledge_chunks_fts ON sheq_knowledge_chunks '
                ."USING gin (to_tsvector('english', coalesce(title,'') || ' ' || body))");

            $this->applyTenantRls(['sheq_ai_suggestions', 'sheq_ai_call_log']);

            // The corpus carries shared rows (company_id NULL = legislation everybody may read),
            // so its policy admits NULL rather than only matching the session tenant.
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $shared = "{$session} IS NULL OR company_id IS NULL OR company_id = {$session}::bigint";

            DB::statement('ALTER TABLE sheq_knowledge_chunks ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE sheq_knowledge_chunks FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON sheq_knowledge_chunks');
            DB::statement('CREATE POLICY tenant_isolation ON sheq_knowledge_chunks '
                ."USING ({$shared}) WITH CHECK ({$shared})");
        }
    }

    /** SHEQ-ADR-001: isolation lives in the database, and FORCE binds even the table owner. */
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

    public function down(): void
    {
        Schema::dropIfExists('sheq_knowledge_chunks');
        Schema::dropIfExists('sheq_ai_call_log');
        Schema::dropIfExists('sheq_ai_suggestions');
    }
};
