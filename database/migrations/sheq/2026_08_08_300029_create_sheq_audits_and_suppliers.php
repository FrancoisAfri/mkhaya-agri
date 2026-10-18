<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit management and supplier SHEQ onboarding — the "Q" in SHEQ.
 *
 * An audit is not a bigger inspection, and conflating the two is the most common way this gets
 * built wrong. An inspection checks a *thing* against a checklist: is the extinguisher charged, is
 * the guard fitted. An audit checks a *system* against a standard: does the organisation actually
 * do what its procedure says, and can it show evidence. The output of an inspection is a defect;
 * the output of an audit is a non-conformance against a numbered clause, which the organisation
 * must then answer with a root cause and a corrective action before a certification body will
 * accept it.
 *
 * That difference drives everything here: findings are graded against a clause, they carry root
 * cause and corrective action, and closing one needs an approval — because in ISO 9001, 14001 and
 * 45001 an auditor verifies the fix, and the auditee closing their own finding is precisely the
 * behaviour surveillance audits exist to catch.
 *
 * Supplier onboarding sits in the same migration because it is the same shape: a scored assessment
 * against required evidence, ending in an approval that lets somebody work. A supplier who has not
 * passed it should not be appointable, and the reason for that is the employer's section 37(2)
 * liability for a mandatary's conduct.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The annual audit plan ─────────────────────────────────────────────────────────────
        Schema::create('sheq_audit_programmes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('name', 160);
            $table->unsignedInteger('year');
            $table->text('objectives')->nullable();

            /** ISO 9001, ISO 14001, ISO 45001, or an internal scheme. */
            $table->string('standard', 60)->nullable();

            $table->string('status', 20)->default('DRAFT');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'year']);
        });

        // ── A template: the questions an audit asks ───────────────────────────────────────────
        Schema::create('sheq_audit_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Null company means a shared library template, as with inspection templates.
            $table->unsignedBigInteger('company_id')->nullable()->index();

            $table->string('code', 40);
            $table->string('title', 200);
            $table->string('standard', 60)->nullable();
            $table->string('audit_type', 30)->default('INTERNAL');

            /**
             * Sections and questions, each carrying the clause it tests.
             *
             * The clause is the point. "Are records retained?" is a housekeeping question;
             * "7.5.3.2 — are documented records controlled and retrievable?" is an audit question,
             * and only the second produces a finding a certification body will recognise.
             */
            $table->jsonb('schema');

            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('PUBLISHED');
            $table->timestamps();
            $table->softDeletes();
        });

        // ── One audit ─────────────────────────────────────────────────────────────────────────
        Schema::create('sheq_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('reference', 40);
            $table->unsignedBigInteger('programme_id')->nullable()->index();
            $table->unsignedBigInteger('template_id')->nullable();

            /** INTERNAL, SUPPLIER, CERTIFICATION, CUSTOMER, REGULATORY. */
            $table->string('audit_type', 30)->index();
            $table->string('standard', 60)->nullable();

            $table->string('title', 200);
            $table->text('scope')->nullable();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            /** Who is being audited — a department, a site, or a supplier. */
            $table->string('auditee_type', 30)->nullable();
            $table->unsignedBigInteger('auditee_id')->nullable();

            $table->unsignedBigInteger('lead_auditor_id')->nullable();
            $table->jsonb('audit_team')->nullable();

            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            /** PLANNED, IN_PROGRESS, FIELDWORK_COMPLETE, REPORTED, CLOSED, CANCELLED. */
            $table->string('status', 25)->default('PLANNED')->index();

            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->nullable();
            $table->boolean('passed')->nullable();

            $table->jsonb('responses')->nullable();
            $table->text('summary')->nullable();
            $table->longText('lead_auditor_signature')->nullable();
            $table->longText('auditee_signature')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference'], 'sheq_audits_reference_unique');
        });

        // ── Findings: what the audit actually produced ────────────────────────────────────────
        Schema::create('sheq_audit_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('audit_id')->index();

            $table->string('reference', 40);

            /**
             * MAJOR_NC, MINOR_NC, OBSERVATION, OFI (opportunity for improvement).
             *
             * The grading is not decoration: a major non-conformance blocks certification and must
             * be closed before the certificate is issued, a minor gets a corrective action plan,
             * and an observation carries no obligation at all. Recording them as one undifferentiated
             * "issue" would make the audit report useless to the people who act on it.
             */
            $table->string('grade', 20)->index();

            /** The clause breached — what makes this a finding rather than an opinion. */
            $table->string('clause', 60)->nullable();
            $table->string('question_id', 60)->nullable();

            $table->text('statement');
            $table->text('evidence')->nullable();

            $table->text('root_cause')->nullable();
            $table->text('correction')->nullable();

            /** The CAPA raised to fix it, so audit findings and actions are one register. */
            $table->unsignedBigInteger('action_id')->nullable()->index();

            $table->unsignedBigInteger('responsible_id')->nullable();
            $table->date('due_date')->nullable();

            /** OPEN, RESPONSE_SUBMITTED, VERIFIED, CLOSED, REJECTED. */
            $table->string('status', 25)->default('OPEN')->index();

            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->text('verification_note')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // ── Supplier SHEQ onboarding ──────────────────────────────────────────────────────────
        Schema::create('sheq_supplier_assessments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            /** The supplier in the procurement module, where one exists. */
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->string('supplier_name', 200);
            $table->string('registration_number', 60)->nullable();
            $table->string('contact_email', 200)->nullable();
            $table->string('contact_phone', 40)->nullable();

            /** What they will do — drives which evidence is required. */
            $table->string('scope_of_work', 255)->nullable();
            $table->string('risk_category', 20)->default('MEDIUM');

            /** INVITED, SUBMITTED, UNDER_REVIEW, APPROVED, CONDITIONAL, REJECTED, EXPIRED. */
            $table->string('status', 25)->default('INVITED')->index();

            $table->decimal('score', 5, 2)->nullable();
            $table->jsonb('responses')->nullable();

            /**
             * Approval expires. A supplier approved on the strength of a letter of good standing
             * that lapsed two years ago has not been assessed — they have been remembered.
             */
            $table->date('valid_until')->nullable();

            $table->text('conditions')->nullable();
            $table->unsignedBigInteger('assessed_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sheq_supplier_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('assessment_id')->index();

            /** LETTER_OF_GOOD_STANDING, PUBLIC_LIABILITY, SAFETY_FILE, ISO_CERT, SOP, MEDICAL. */
            $table->string('doc_type', 60);
            $table->string('title', 200)->nullable();

            $table->unsignedBigInteger('file_id')->nullable();

            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('is_mandatory')->default(true);

            /** PENDING, ACCEPTED, REJECTED, EXPIRED. */
            $table->string('status', 20)->default('PENDING');
            $table->text('review_note')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            $shared = "{$session} IS NULL OR company_id IS NULL OR company_id = {$session}::bigint";

            foreach (['sheq_audit_programmes', 'sheq_audits', 'sheq_audit_findings',
                'sheq_supplier_assessments', 'sheq_supplier_documents'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }

            // Templates carry a shared library, so NULL company must remain readable.
            DB::statement('ALTER TABLE sheq_audit_templates ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE sheq_audit_templates FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON sheq_audit_templates');
            DB::statement("CREATE POLICY tenant_isolation ON sheq_audit_templates USING ({$shared}) WITH CHECK ({$shared})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_supplier_documents');
        Schema::dropIfExists('sheq_supplier_assessments');
        Schema::dropIfExists('sheq_audit_findings');
        Schema::dropIfExists('sheq_audits');
        Schema::dropIfExists('sheq_audit_templates');
        Schema::dropIfExists('sheq_audit_programmes');
    }
};
