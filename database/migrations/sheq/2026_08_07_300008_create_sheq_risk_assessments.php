<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Risk assessment — HIRA (§5.3).
 *
 * SHEQ-RA-1 is explicit that the matrix is tenant-configurable from 3×3 to 6×6, in dimensions,
 * labels, bands, colours and band-to-approval-role mapping, with no hard-coded 5×5. That single
 * requirement drives the whole shape here: the matrix is a row, the bands are data, and scoring is
 * a lookup rather than a constant in code. A mine using a 6×6 with its own consequence descriptors
 * is not an edge case, it is the normal case.
 *
 * Scores are generated columns so likelihood × severity cannot drift from what was displayed when
 * the assessment was approved — the database computes it, not the application, and not the UI.
 *
 * Team participation (SHEQ-RA-5) is its own table because Construction Regulations and the MHSA
 * both require demonstrable worker participation. An auditor asks "who was in the room", and a
 * comma-separated string in a text column is not an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The matrix (SHEQ-RA-1) ────────────────────────────────────────────────────────────
        Schema::create('sheq_risk_matrices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->unsignedTinyInteger('likelihood_levels');   // rows, 3..6
            $table->unsignedTinyInteger('severity_levels');     // cols, 3..6

            $table->jsonb('likelihood_labels');   // [{value, label, descriptor}]
            $table->jsonb('severity_labels');
            // [{min, max, label, colour, action, max_approval_role}] — approval authority is part
            // of the matrix, so raising a risk band raises who must sign it (SHEQ-RA-3).
            $table->jsonb('bands');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_default']);
        });

        // ── The assessment ────────────────────────────────────────────────────────────────────
        Schema::create('sheq_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference', 40);

            // BASELINE|ISSUE_BASED|CONTINUOUS|TASK|JSA|CHANGE
            $table->string('ra_type', 20)->default('BASELINE');
            $table->string('title');
            $table->text('scope')->nullable();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->foreignId('matrix_id')->constrained('sheq_risk_matrices')->restrictOnDelete();

            // DRAFT|IN_REVIEW|APPROVED|SUPERSEDED|EXPIRED
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedInteger('version')->default(1);
            // Superseded versions stay readable forever (SHEQ-RA-4).
            $table->foreignId('supersedes_id')->nullable()->constrained('sheq_risk_assessments')->nullOnDelete();

            $table->date('effective_from')->nullable();
            $table->date('review_due')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            // The band that set the approval bar, frozen at approval time so a later matrix edit
            // cannot retroactively make an approval look valid — or invalid.
            $table->string('approval_band')->nullable();
            $table->string('approval_role_required', 60)->nullable();

            // SHEQ-RA-6: why the next review is due, so the trigger is visible not implied.
            $table->string('review_trigger', 30)->nullable();  // SCHEDULE|INCIDENT|CHANGE|LEGISLATION

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference', 'version']);
            $table->index(['company_id', 'status', 'review_due']);
            $table->index(['company_id', 'site_id', 'status']);
        });

        // ── Participation (SHEQ-RA-5) ─────────────────────────────────────────────────────────
        Schema::create('sheq_risk_assessment_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('risk_assessment_id')->constrained('sheq_risk_assessments')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('hr_person_id')->nullable();
            $table->string('name');                       // captured for non-system participants
            $table->string('participant_role', 60)->nullable();  // FACILITATOR|WORKER|SUPERVISOR|SPECIALIST

            $table->timestampTz('signed_at')->nullable();
            $table->text('signature')->nullable();        // data URI or evidence reference

            $table->timestamps();

            $table->index(['company_id', 'risk_assessment_id']);
        });

        // ── Risk lines ────────────────────────────────────────────────────────────────────────
        Schema::create('sheq_risk_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('risk_assessment_id')->constrained('sheq_risk_assessments')->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('activity');
            $table->text('hazard');
            $table->text('potential_harm')->nullable();

            $table->unsignedTinyInteger('inherent_likelihood');
            $table->unsignedTinyInteger('inherent_severity');
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_severity')->nullable();

            $table->string('inherent_band', 40)->nullable();
            $table->string('residual_band', 40)->nullable();

            $table->foreignId('risk_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_acceptable')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'risk_assessment_id', 'sequence']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Generated, so the stored score can never disagree with its own inputs.
            DB::statement('ALTER TABLE sheq_risk_lines ADD COLUMN inherent_score INTEGER
                           GENERATED ALWAYS AS (inherent_likelihood * inherent_severity) STORED');
            DB::statement('ALTER TABLE sheq_risk_lines ADD COLUMN residual_score INTEGER
                           GENERATED ALWAYS AS (residual_likelihood * residual_severity) STORED');
            DB::statement('CREATE INDEX sheq_risk_lines_residual_idx ON sheq_risk_lines (company_id, residual_score DESC)');
        }

        // ── Controls (SHEQ-RA-2, RA-7, RA-8) ──────────────────────────────────────────────────
        Schema::create('sheq_controls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('risk_line_id')->constrained('sheq_risk_lines')->cascadeOnDelete();

            // ELIMINATION|SUBSTITUTION|ENGINEERING|ADMINISTRATIVE|PPE
            $table->string('hierarchy_level', 20);
            $table->text('description');
            $table->string('control_type', 20)->default('STANDARD');   // CRITICAL|STANDARD (RA-8)

            $table->boolean('is_implemented')->default(false);
            $table->string('verification_method', 120)->nullable();
            $table->string('verification_frequency', 40)->nullable();

            // SHEQ-RA-7: a control linked to a document, competency or inspection template can be
            // verified automatically. An unlinked control is a claim; a linked one is evidence.
            $table->unsignedBigInteger('linked_document_id')->nullable();
            $table->unsignedBigInteger('linked_competency_id')->nullable();
            $table->unsignedBigInteger('linked_inspection_template_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'risk_line_id']);
            $table->index(['company_id', 'control_type', 'is_implemented']);
        });

        $this->applyTenantRls([
            'sheq_risk_matrices', 'sheq_risk_assessments', 'sheq_risk_assessment_participants',
            'sheq_risk_lines', 'sheq_controls',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_controls');
        Schema::dropIfExists('sheq_risk_lines');
        Schema::dropIfExists('sheq_risk_assessment_participants');
        Schema::dropIfExists('sheq_risk_assessments');
        Schema::dropIfExists('sheq_risk_matrices');
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
