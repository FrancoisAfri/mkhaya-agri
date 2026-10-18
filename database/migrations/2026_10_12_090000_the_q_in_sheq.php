<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quality management (QLT-1 to QLT-8).
 *
 * The module was a safety system with quality mentioned in passing: an inspection could record a
 * score, and nothing could record that the concrete failed its 28-day strength, who owed the
 * rework, what it cost, or whether the same defect had already happened four times on the same
 * floor.
 *
 * That asymmetry is not cosmetic. On a construction site the quality failures and the safety
 * failures have the same causes — rushed work, the wrong competency, a procedure nobody read —
 * and a system that counts one and not the other produces a contractor score that is half an
 * answer. QLT-8's contractor quality score is the point at which the two halves meet.
 *
 * Three things here deliberately do NOT get their own table, because they already exist:
 *
 * **Root cause (QLT-5).** `sheq_investigations` already does 5-Why, Fishbone and ICAM with a
 * minimum depth per method and an approval step. A second root-cause engine for quality would
 * drift from the first within a release — so investigations become attachable to any subject
 * instead, which is what the columns added here are for.
 *
 * **Corrective actions (QLT-6).** `sheq_actions` is the CAPA register. The requirement says "the
 * existing unified CAPA system" in so many words.
 *
 * **Inspection templates (QLT-2).** `sheq_inspection_templates` is already versioned, already
 * has a schema and a pass threshold. It needed one column — which discipline it belongs to — so
 * that a quality template for concrete does not appear in a safety inspector's list.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * What "good" means here (QLT-1).
         *
         * One table for objectives, standards, specifications, project requirements and
         * acceptance criteria, because they are the same shape: a statement of what is required,
         * a source it comes from, and a measurable test. Five tables would mean five screens and
         * five sets of the same fields, and an NCR would have to decide which of five things it
         * breached.
         */
        Schema::create('sheq_quality_requirements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference', 40);

            /** OBJECTIVE, STANDARD, SPECIFICATION, PROJECT_REQUIREMENT or ACCEPTANCE_CRITERION. */
            $table->string('requirement_type', 30);

            $table->string('title', 255);
            $table->text('description')->nullable();

            /** Where it comes from: SANS 10100, a client specification, a drawing revision. */
            $table->string('source', 255)->nullable();

            /**
             * The measurable test, in words.
             *
             * Free text rather than a structured tolerance, because the real ones are not
             * uniform: "25 MPa at 28 days", "no visible efflorescence", "within 3 mm over 3 m".
             * A structured field would fit a third of them and force the rest into a comment.
             */
            $table->text('acceptance_criteria')->nullable();

            /** A target and its unit, where the requirement is a number. */
            $table->decimal('target_value', 14, 4)->nullable();
            $table->string('target_unit', 40)->nullable();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            /** The discipline this belongs to: CONCRETE, WELDING, ELECTRICAL and so on. */
            $table->string('discipline', 40)->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'requirement_type'], 'sheq_qual_req_type_idx');
            $table->index(['company_id', 'discipline'], 'sheq_qual_req_discipline_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_quality_requirement_ref_uniq ON sheq_quality_requirements '
            .'(company_id, reference) WHERE deleted_at IS NULL'
        );

        /**
         * Non-conformance reports (QLT-3).
         *
         * The register the whole section turns on. Everything QLT-3 lists is a column rather
         * than a note, and the reason is QLT-8: "repeat defects" cannot be counted from prose.
         * A defect recorded as "honeycombing on the column again" is unfindable; one recorded
         * against a defect code, a location and a contractor is countable.
         */
        Schema::create('sheq_ncrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference', 40);

            /** What was breached (QLT-3's "requirement"). */
            $table->foreignId('quality_requirement_id')->nullable()
                ->constrained('sheq_quality_requirements')->nullOnDelete();

            /** And in words, for a requirement that is not in the register. */
            $table->string('requirement_text', 500)->nullable();

            /** The defect, coded so repeats can be counted, and described so they can be read. */
            $table->string('defect_code', 60)->nullable();
            $table->text('defect')->nullable();

            $table->string('discipline', 40)->nullable();

            // ── Where ────────────────────────────────────────────────────────────────────────
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            /**
             * The location within the site, as a person would say it.
             *
             * "Block B, level 3, column C4". Free text because no two sites grid themselves the
             * same way, and a structured location model that does not match the drawings is one
             * nobody fills in.
             */
            $table->string('location_text', 255)->nullable();

            // ── Who ──────────────────────────────────────────────────────────────────────────
            $table->foreignId('contractor_id')->nullable()
                ->constrained('sheq_contractors')->nullOnDelete();

            /** The person who has to answer it (QLT-3's "responsible person"). */
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('raised_at')->nullable();

            // ── How bad ──────────────────────────────────────────────────────────────────────
            /**
             * MINOR, MAJOR or CRITICAL.
             *
             * CRITICAL and MAJOR are what QLT-7 calls high severity: those cannot be closed by
             * whoever did the corrective work.
             */
            $table->string('severity', 20)->default('MINOR');

            $table->date('due_date')->nullable();

            /** OPEN, IN_PROGRESS, AWAITING_VERIFICATION, CLOSED or VOID. */
            $table->string('status', 30)->default('OPEN');

            /** The CAPA raised from it (QLT-6). */
            $table->foreignId('action_id')->nullable()
                ->constrained('sheq_actions')->nullOnDelete();

            /** The inspection that found it, where one did. */
            $table->foreignId('inspection_id')->nullable()
                ->constrained('sheq_inspections')->nullOnDelete();

            // ── Closing it (QLT-7) ───────────────────────────────────────────────────────────
            /**
             * Who did the corrective work, and who verified it. Two columns, never one.
             *
             * The whole of QLT-7 is that these must differ for a high-severity NCR. Storing one
             * "closed_by" would make the rule unenforceable after the fact — and unauditable,
             * which is worse, because the defence against a claim is the record.
             */
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('corrected_at')->nullable();
            $table->text('correction_notes')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->text('verification_notes')->nullable();

            $table->timestampTz('closed_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status', 'severity'], 'sheq_ncr_status_idx');
            $table->index(['company_id', 'contractor_id'], 'sheq_ncr_contractor_idx');
            // "Repeat defects" (QLT-8) reads exactly this shape.
            $table->index(['company_id', 'defect_code', 'raised_at'], 'sheq_ncr_defect_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_ncr_reference_uniq ON sheq_ncrs '
            .'(company_id, reference) WHERE deleted_at IS NULL'
        );

        /**
         * Rework (QLT-4).
         *
         * Separate from the NCR because one non-conformance can produce several bouts of rework
         * — and because rework is the figure that gets a quality system funded. Safety is argued
         * for on principle; rework is argued for in rands, and a contractor who has cost the
         * project four hundred thousand in rework is a conversation that happens with or without
         * this table. It may as well have the numbers.
         */
        Schema::create('sheq_rework', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('ncr_id')->nullable()->constrained('sheq_ncrs')->nullOnDelete();

            $table->string('description', 500);

            /** Why it had to be done again, from a vocabulary so QLT-8 can group it. */
            $table->string('reason_code', 60)->nullable();
            $table->text('reason')->nullable();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            /** Who bears it. The question the whole table exists to answer. */
            $table->foreignId('contractor_id')->nullable()
                ->constrained('sheq_contractors')->nullOnDelete();

            /**
             * Cost in minor units, with its currency.
             *
             * Minor units because money in a float is wrong by a cent somewhere and this figure
             * ends up in a commercial argument. The currency travels with it because the
             * platform is sold into Eswatini and Botswana as well as South Africa.
             */
            $table->bigInteger('cost_minor')->nullable();
            $table->string('cost_currency', 3)->default('ZAR');

            /** Time lost, in hours. Often the larger cost on a critical path. */
            $table->decimal('hours', 10, 2)->nullable();

            $table->date('occurred_on')->nullable();

            /** Whether the redone work has been checked, and by whom (QLT-4's "verification"). */
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->text('verification_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'contractor_id'], 'sheq_rework_contractor_idx');
            $table->index(['company_id', 'occurred_on'], 'sheq_rework_date_idx');
        });

        /*
         * QLT-5: let an investigation hang off something other than an incident.
         *
         * `sheq_investigations` already enforces a method, a minimum number of causes per method,
         * a required depth derived from potential severity, and the rule that an individual's
         * mistake is not a root cause. Building a second engine for quality would mean two sets
         * of those rules, and within a release they would disagree about what an adequate
         * investigation is.
         *
         * `incident_id` becomes nullable and a subject pair is added alongside it. Every existing
         * row keeps its incident id and every existing query keeps working, which is why the old
         * column is not migrated into the new one — a rewrite of live data to make a schema
         * tidier is a risk taken for no benefit.
         */
        Schema::table('sheq_investigations', function (Blueprint $table) {
            $table->string('subject_type', 40)->nullable()->after('incident_id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');

            $table->index(['company_id', 'subject_type', 'subject_id'], 'sheq_inv_subject_idx');
        });

        DB::statement('ALTER TABLE sheq_investigations ALTER COLUMN incident_id DROP NOT NULL');

        /*
         * QLT-2: which discipline a template belongs to.
         *
         * Without it a quality template for concrete appears in a safety inspector's list and
         * vice versa, and both lists become long enough that people stop reading them. Nullable,
         * so every template that exists today stays exactly where it is.
         */
        Schema::table('sheq_inspection_templates', function (Blueprint $table) {
            $table->string('discipline', 40)->nullable()->after('subject_type');
            $table->index(['company_id', 'discipline'], 'sheq_template_discipline_idx');
        });

        $this->applyTenantRls(['sheq_quality_requirements', 'sheq_ncrs', 'sheq_rework']);
    }

    public function down(): void
    {
        Schema::table('sheq_inspection_templates', function (Blueprint $table) {
            $table->dropIndex('sheq_template_discipline_idx');
            $table->dropColumn('discipline');
        });

        Schema::table('sheq_investigations', function (Blueprint $table) {
            $table->dropIndex('sheq_inv_subject_idx');
            $table->dropColumn(['subject_type', 'subject_id']);
        });

        Schema::dropIfExists('sheq_rework');
        Schema::dropIfExists('sheq_ncrs');
        Schema::dropIfExists('sheq_quality_requirements');
    }

    /** The same row-level security every other SHEQ table carries. */
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
