<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents & evidence (§8).
 *
 * The requirement that shapes this schema is the last line of §8: "users should never have to
 * upload the same document repeatedly". That rules out an attachment table per feature, which is
 * what every module ends up with by accident. Instead there is one register, and a document is
 * *linked* to as many procurements, suppliers, tenders, contracts and deviations as apply. A
 * B-BBEE certificate uploaded once during supplier onboarding is the same row an evaluator sees
 * three tenders later, and it expires in one place.
 *
 * Versions are rows, not overwrites (§8 "version control"): superseding a document keeps the old
 * file addressable, because an evaluation that scored version 2 must still be readable as having
 * scored version 2.
 *
 * Access is decided by three things together — the confidentiality level, the explicit grants in
 * `procgov_document_permissions`, and the reader's module tier. Every actual read is written to
 * `procgov_document_access_log`, because §8 asks for upload/download history and §7 wants document
 * activity in the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procgov_document_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->string('code', 40);
            $t->string('name');
            $t->text('description')->nullable();

            // Defaults applied to documents filed under this category, so a tenant configures the
            // rule once rather than per upload.
            $t->string('default_confidentiality', 20)->default('INTERNAL');
            $t->boolean('requires_expiry')->default(false);
            $t->boolean('requires_approval')->default(false);
            $t->boolean('requires_acknowledgement')->default(false);
            $t->unsignedSmallInteger('retention_months')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['company_id', 'code'], 'procgov_doc_cat_unique');
        });

        Schema::create('procgov_documents', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('reference', 40)->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->foreignId('category_id')->nullable()->constrained('procgov_document_categories')->nullOnDelete();

            // PUBLIC | INTERNAL | CONFIDENTIAL | RESTRICTED. Bid submissions sit at CONFIDENTIAL
            // until the tender closes; an evaluator's own working notes at RESTRICTED.
            $t->string('confidentiality', 20)->default('INTERNAL');

            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            // DRAFT | PENDING_APPROVAL | APPROVED | REJECTED | SUPERSEDED | ARCHIVED
            $t->string('approval_status', 24)->default('APPROVED');
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();

            $t->date('effective_from')->nullable();
            $t->date('expires_at')->nullable();          // §9 drives the expiring-documents notice
            $t->boolean('requires_acknowledgement')->default(false);

            $t->unsignedSmallInteger('version_count')->default(0);
            $t->unsignedBigInteger('current_version_id')->nullable();

            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'confidentiality'], 'procgov_docs_conf_idx');
            $t->index(['company_id', 'expires_at'], 'procgov_docs_expiry_idx');
            $t->index(['company_id', 'approval_status'], 'procgov_docs_status_idx');
        });

        Schema::create('procgov_document_versions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('document_id')->constrained('procgov_documents')->cascadeOnDelete();

            $t->unsignedSmallInteger('version_no');
            $t->string('disk', 40)->default('local');
            $t->string('path', 500);
            $t->string('original_filename', 255);
            $t->string('mime_type', 120)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();

            // Content hash: proves the file backing an evaluation has not been swapped since.
            $t->char('checksum', 64)->nullable();

            $t->text('change_note')->nullable();
            $t->boolean('is_current')->default(true);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['document_id', 'version_no'], 'procgov_doc_ver_unique');
            $t->index(['company_id', 'document_id', 'is_current'], 'procgov_doc_ver_current_idx');
        });

        // One document, many contexts — this is what removes the repeat uploads.
        Schema::create('procgov_document_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('document_id')->constrained('procgov_documents')->cascadeOnDelete();

            // record | supplier | contract | tender_review | bid | deviation | plan | committee …
            $t->string('link_role', 40);
            $t->string('source_module', 60)->default('procurement_governance');
            $t->string('entity_type', 80);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->uuid('entity_uuid')->nullable();
            $t->string('entity_reference', 80)->nullable();

            // A document can be mandatory *for this context* without being mandatory in general —
            // the rules engine (§5.3) reads this when it checks required documents.
            $t->boolean('is_required')->default(false);
            $t->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['document_id', 'entity_type', 'entity_id', 'link_role'], 'procgov_doc_link_unique');
            $t->index(['company_id', 'entity_type', 'entity_id'], 'procgov_doc_link_entity_idx');
        });

        // Explicit grants layered on top of the confidentiality level. Absent any grant, the level
        // plus the reader's module tier decides; a grant can widen access to a named person,
        // role or evaluation committee without lowering the document's classification.
        Schema::create('procgov_document_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('document_id')->constrained('procgov_documents')->cascadeOnDelete();

            $t->string('subject_type', 20);          // user | role | committee
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('subject_label', 190)->nullable();

            $t->boolean('can_view')->default(true);
            $t->boolean('can_download')->default(false);
            $t->boolean('can_manage')->default(false);

            $t->timestamp('expires_at')->nullable();
            $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['document_id', 'subject_type', 'subject_id'], 'procgov_doc_perm_unique');
            $t->index(['company_id', 'subject_type', 'subject_id'], 'procgov_doc_perm_subject_idx');
        });

        Schema::create('procgov_document_access_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('document_id')->constrained('procgov_documents')->cascadeOnDelete();
            $t->unsignedBigInteger('version_id')->nullable();

            $t->string('action', 20);                // uploaded | viewed | downloaded | denied | superseded
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('context', 190)->nullable();  // what they were doing when they opened it
            $t->timestamp('created_at')->nullable();

            $t->index(['company_id', 'document_id', 'created_at'], 'procgov_doc_access_doc_idx');
            $t->index(['company_id', 'user_id', 'created_at'], 'procgov_doc_access_user_idx');
        });

        Schema::create('procgov_document_acknowledgements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('document_id')->constrained('procgov_documents')->cascadeOnDelete();
            $t->unsignedBigInteger('version_id')->nullable();

            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->string('statement', 500)->nullable();   // what exactly they acknowledged
            $t->string('ip', 45)->nullable();
            $t->timestamps();

            $t->unique(['document_id', 'version_id', 'user_id'], 'procgov_doc_ack_unique');
            $t->index(['company_id', 'user_id'], 'procgov_doc_ack_user_idx');
        });

        $this->applyTenantRls([
            'procgov_document_categories',
            'procgov_documents',
            'procgov_document_versions',
            'procgov_document_links',
            'procgov_document_permissions',
            'procgov_document_access_log',
            'procgov_document_acknowledgements',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_document_acknowledgements');
        Schema::dropIfExists('procgov_document_access_log');
        Schema::dropIfExists('procgov_document_permissions');
        Schema::dropIfExists('procgov_document_links');
        Schema::dropIfExists('procgov_document_versions');
        Schema::dropIfExists('procgov_documents');
        Schema::dropIfExists('procgov_document_categories');
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
