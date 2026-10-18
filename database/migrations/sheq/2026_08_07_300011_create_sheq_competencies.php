<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Competency and legal appointments (§5.6).
 *
 * SHEQ-COMP-1 draws the line: SHEQ stores *requirements, references and derived compliance state*.
 * It never stores training content, assessments or completion detail — those belong to LMS. So
 * there is no course table here, no question bank, no score breakdown; there is a competency
 * definition, who needs it, and what state each person is in.
 *
 * `sheq_competency_records` is a projection in the sense of SHEQ-ADR-002: rebuilt from LMS plus
 * manually captured certificates, carrying `source` and `last_synced_at` so staleness is visible
 * rather than assumed. `source_version` is present for the day LMS emits events and this becomes a
 * true event projection — the switch will need no migration.
 *
 * Appointments (SHEQ-COMP-8) are modelled as a competency type rather than a separate feature, so
 * OHSA 16(2), 17, GSR and MHSA 2.9.2 appointments inherit the same expiry, matrix and gating
 * machinery as a forklift licence. They add what a letter needs: scope, a signed document and the
 * appointee's acceptance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_competencies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('title');
            $table->text('description')->nullable();

            // TRAINING|LICENCE|MEDICAL|APPOINTMENT|EXPERIENCE
            $table->string('competency_type', 20)->default('TRAINING');

            // Reference only — the course lives in LMS (SHEQ-COMP-1).
            $table->unsignedBigInteger('lms_course_id')->nullable();
            $table->unsignedBigInteger('lms_certification_id')->nullable();
            $table->string('external_provider')->nullable();

            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('validity_months')->nullable();   // null = never expires
            $table->decimal('pass_mark', 5, 2)->nullable();
            $table->boolean('practical_required')->default(false);

            // e.g. "OHSA GSR 3", "MHSA s21" — what makes this legally required.
            $table->string('regulatory_reference', 120)->nullable();

            // SHEQ-COMP-9: an expired competency of this kind blocks high-risk work.
            $table->boolean('blocks_work_when_expired')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'competency_type', 'is_active']);
        });

        // ── Who needs it (SHEQ-COMP-2) ────────────────────────────────────────────────────────
        Schema::create('sheq_competency_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('sheq_competencies')->cascadeOnDelete();

            // ROLE|DEPARTMENT|SITE|EMPLOYEE|CONTRACTOR_TYPE|TASK — precedence is
            // individual > task > role > department > site, resolved in CompetencyService.
            $table->string('applies_to_type', 20);
            $table->string('applies_to_id', 120);      // string: a role name, a task code, an id

            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->date('effective_from')->nullable();
            $table->boolean('is_exempt')->default(false);   // an explicit "not required here"

            $table->timestamps();

            $table->unique(['company_id', 'competency_id', 'applies_to_type', 'applies_to_id'], 'sheq_comp_req_unique');
            $table->index(['company_id', 'applies_to_type', 'applies_to_id']);
        });

        // ── Derived state per person (SHEQ-COMP-4) ────────────────────────────────────────────
        Schema::create('sheq_competency_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('competency_id')->constrained('sheq_competencies')->cascadeOnDelete();
            $table->unsignedBigInteger('hr_person_id');

            // COMPLIANT|EXPIRING|EXPIRED|NOT_STARTED|IN_PROGRESS|EXEMPT|FAILED
            $table->string('status', 20)->default('NOT_STARTED');

            $table->date('achieved_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->decimal('score', 5, 2)->nullable();

            // The certificate *reference*, never the certificate's contents.
            $table->string('certificate_ref')->nullable();
            $table->string('certificate_number')->nullable();
            $table->string('issuing_body')->nullable();
            $table->string('evidence_path')->nullable();     // uploaded proof for manual capture

            $table->string('source', 20)->default('MANUAL'); // LMS|MANUAL|EXTERNAL_IMPORT
            $table->timestampTz('last_synced_at')->nullable();
            $table->unsignedBigInteger('source_version')->nullable();   // for the event-sourced future

            // ── Appointment fields (SHEQ-COMP-8) ──────────────────────────────────────────────
            $table->text('appointment_scope')->nullable();
            $table->unsignedBigInteger('appointment_document_id')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->text('acceptance_signature')->nullable();
            $table->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'hr_person_id', 'competency_id'], 'sheq_comp_record_unique');
            $table->index(['company_id', 'status', 'expires_at']);
            $table->index(['company_id', 'competency_id', 'status']);
        });

        $this->applyTenantRls([
            'sheq_competencies', 'sheq_competency_requirements', 'sheq_competency_records',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_competency_records');
        Schema::dropIfExists('sheq_competency_requirements');
        Schema::dropIfExists('sheq_competencies');
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
