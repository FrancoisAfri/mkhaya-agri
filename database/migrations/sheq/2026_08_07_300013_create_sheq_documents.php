<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document control (§5.8).
 *
 * The document is the identity; the version is the artefact. Splitting them is what makes
 * SHEQ-DOC-1 possible: a superseded version stays readable forever, and an acknowledgement points
 * at the version the person actually read — not at a document whose contents have since changed.
 *
 * That last point is the whole reason acknowledgements carry a version id. "S. Dlamini acknowledged
 * the Working at Heights procedure" is worthless if the procedure has been rewritten twice since;
 * "acknowledged v3 on 12 March" is evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('doc_number', 60);
            $table->string('title');
            // POLICY|PROCEDURE|SOP|WORK_INSTRUCTION|FORM|LEGAL_APPOINTMENT|SAFE_WORK_METHOD|CONTRACTOR
            $table->string('doc_type', 30)->default('PROCEDURE');

            $table->unsignedInteger('current_version')->default(0);
            // DRAFT|IN_REVIEW|APPROVED|PUBLISHED|SUPERSEDED|WITHDRAWN
            $table->string('status', 20)->default('DRAFT');

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('published_at')->nullable();
            $table->date('review_due')->nullable();

            // SHEQ-DOC-2: who must read it, targeted by role / department / site.
            $table->boolean('requires_acknowledgement')->default(false);
            $table->jsonb('applies_to')->nullable();

            // SHEQ-DOC-4: published content is chunked into the tenant's vector index; superseded
            // versions are removed from it, so retrieval can never answer from a withdrawn procedure.
            $table->boolean('is_indexed_for_ai')->default(true);
            $table->timestampTz('indexed_at')->nullable();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'doc_number']);
            $table->index(['company_id', 'status', 'review_due']);
        });

        Schema::create('sheq_document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('sheq_documents')->cascadeOnDelete();

            $table->unsignedInteger('version');
            $table->string('status', 20)->default('DRAFT');   // DRAFT|IN_REVIEW|PUBLISHED|SUPERSEDED

            $table->text('summary_of_changes')->nullable();
            $table->string('file_path')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            // Tamper evidence, same reasoning as the evidence ledger: a controlled document whose
            // bytes can change without trace is not controlled.
            $table->char('sha256', 64)->nullable();

            $table->foreignId('authored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'document_id', 'version'], 'sheq_doc_version_unique');
            $table->index(['company_id', 'status']);
        });

        Schema::create('sheq_document_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('sheq_documents')->cascadeOnDelete();
            // The version read, not just the document — see the class docblock.
            $table->foreignId('document_version_id')->constrained('sheq_document_versions')->cascadeOnDelete();

            $table->unsignedBigInteger('hr_person_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('acknowledged_at')->nullable();
            $table->text('signature')->nullable();
            $table->string('ip_address', 45)->nullable();

            // Optional comprehension check (SHEQ-DOC-2, P2) — score kept, answers are LMS's business.
            $table->decimal('comprehension_score', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'document_version_id', 'hr_person_id'], 'sheq_doc_ack_unique');
            $table->index(['company_id', 'document_id', 'acknowledged_at']);
        });

        $this->applyTenantRls([
            'sheq_documents', 'sheq_document_versions', 'sheq_document_acknowledgements',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_document_acknowledgements');
        Schema::dropIfExists('sheq_document_versions');
        Schema::dropIfExists('sheq_documents');
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
