<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What enforces a control, and what happened to it (RISK-1, RISK-3, RISK-4).
 *
 * The HIRA engine is the most complete thing in this module: matrices, hierarchy-of-control
 * checks, approval authority by risk band, versioning, participation, review triggers. A control
 * can already point at the document that describes it, the competency that qualifies somebody to
 * apply it and the inspection template that checks it.
 *
 * What it cannot do is notice that a control is not working.
 *
 * That is the whole of RISK-3, and the reason is structural rather than an oversight: nothing
 * connected a control to the EVIDENCE about it. An inspection template is linked, so the
 * inspections exist — and no column recorded that this control was the thing that failed. So a
 * site could fail the same question on the same checklist eleven times and the risk assessment
 * would still read "control implemented: yes", because somebody ticked that box in 2024 and
 * nothing has contradicted it since.
 *
 * Two additions:
 *
 * **The last two links RISK-4 asks for** — the permit regime that governs the work and the
 * activity it belongs to. With those, a control is connected to every mechanism that could
 * enforce it.
 *
 * **Verification outcomes** — a record, per control, of each time it was checked and what was
 * found. That is the raw material the deterioration detector reads, and without it "this control
 * repeatedly fails" is a sentence nobody can compute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_controls', function (Blueprint $table) {
            /**
             * The permit type that governs work under this control (RISK-4).
             *
             * A type rather than a permit id: a control is a standing requirement and permits
             * are issued per job, so pointing at one permit would make the link go stale the
             * moment that job closed.
             */
            $table->string('linked_permit_type', 40)->nullable()->after('linked_competency_id');

            /** And the activity it applies to, from the same catalogue the gate reads. */
            $table->string('linked_activity', 60)->nullable()->after('linked_permit_type');

            /**
             * When this control was last verified and what was found.
             *
             * Denormalised onto the control deliberately. The verification history lives in its
             * own table below, and every screen that shows a control wants to know whether it is
             * currently working — a per-control aggregate across three registers is not a query
             * to run while rendering a risk assessment with forty lines on it.
             */
            $table->date('last_verified_on')->nullable();
            $table->string('last_verification_result', 20)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);

            $table->index(['company_id', 'linked_activity'], 'sheq_control_activity_idx');
            $table->index(['company_id', 'consecutive_failures'], 'sheq_control_failures_idx');
        });

        /**
         * Each time a control was checked, and what was found (RISK-3).
         *
         * Its own table rather than a column, because a control's effectiveness is a history and
         * not a state: "failed twice last year and has been fine since" and "fine until last
         * month and has failed twice since" are the same two failures and opposite conclusions.
         */
        Schema::create('sheq_control_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('control_id')->constrained('sheq_controls')->cascadeOnDelete();

            /**
             * Where the evidence came from.
             *
             * INSPECTION, INCIDENT, OBSERVATION, AUDIT, CAPA_REVIEW or MANUAL. The four the
             * requirement names plus the two that produce the same kind of evidence — an audit
             * finding against a control and a CAPA that was reviewed as ineffective both say the
             * control did not work.
             */
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference', 80)->nullable();

            /** EFFECTIVE, PARTIAL or FAILED. */
            $table->string('result', 20);

            $table->date('verified_on');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'control_id', 'verified_on'],
                'sheq_control_verification_idx');
            $table->index(['company_id', 'result'], 'sheq_control_result_idx');
        });

        /**
         * A snapshot of a risk line's score each time it moved (RISK-1).
         *
         * Risk assessments are versioned, so the history exists — but it exists as whole
         * documents, and "did the residual risk on THIS line go up" cannot be answered by
         * diffing two versions of a forty-line assessment in a screen. The movement is the fact
         * worth storing, and it is stored at the moment it happens because reconstructing it
         * later means re-reading every superseded revision.
         */
        Schema::create('sheq_risk_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('risk_assessment_id')
                ->constrained('sheq_risk_assessments')->cascadeOnDelete();
            $table->foreignId('risk_line_id')->nullable()
                ->constrained('sheq_risk_lines')->nullOnDelete();

            /** Carried so a movement survives the line being superseded. */
            $table->string('activity', 255)->nullable();
            $table->string('hazard', 255)->nullable();
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            $table->unsignedSmallInteger('from_score')->nullable();
            $table->unsignedSmallInteger('to_score')->nullable();
            $table->string('from_band', 40)->nullable();
            $table->string('to_band', 40)->nullable();

            /**
             * Which way it went.
             *
             * INCREASED is the one RISK-1 is about. A residual risk that goes UP after a
             * reassessment means either the controls stopped working or somebody was optimistic
             * the first time, and both are worth somebody's attention — whereas a decrease is the
             * system working.
             */
            $table->string('direction', 20);

            $table->string('trigger', 40)->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('moved_at');

            $table->timestamps();

            $table->index(['company_id', 'direction', 'moved_at'], 'sheq_risk_movement_idx');
            $table->index(['company_id', 'site_id'], 'sheq_risk_movement_site_idx');
        });

        $this->applyTenantRls(['sheq_control_verifications', 'sheq_risk_movements']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_risk_movements');
        Schema::dropIfExists('sheq_control_verifications');

        Schema::table('sheq_controls', function (Blueprint $table) {
            $table->dropIndex('sheq_control_activity_idx');
            $table->dropIndex('sheq_control_failures_idx');
            $table->dropColumn(['linked_permit_type', 'linked_activity', 'last_verified_on',
                'last_verification_result', 'consecutive_failures']);
        });
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
