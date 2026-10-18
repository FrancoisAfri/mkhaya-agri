<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document Management — the central repository (Operations & Support).
 *
 * The shape that matters here is that a document and the file it currently holds are two different
 * things. "Replace an existing document while retaining its history" is not a feature bolted onto a
 * files table; it is the reason versions are their own rows. A document keeps its identity, its
 * description, its permissions and its place in the tree while the bytes underneath it change, and
 * every version that ever held that place is still there to be read.
 *
 * Storage follows the platform: bytes go to the `documents` disk, which is the database-backed
 * filesystem adapter, so a self-hosted installation needs no object store and a tenant's files are
 * isolated by the same row-level security as everything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Where documents live ─────────────────────────────────────────────
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            // Materialised so a breadcrumb or a subtree query is one read rather than a walk.
            $table->string('path', 1000)->nullable()->index();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->boolean('is_confidential')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'parent_id']);
        });

        // ── The document, which outlives any single file ─────────────────────
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->index();
            $table->unsignedBigInteger('folder_id')->nullable()->index();

            $table->string('title');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();

            // active | archived. Archived is reversible and keeps the document findable to anybody
            // who may see it; deletion is a separate, permissioned act.
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->string('archive_reason')->nullable();

            /*
             * Two different sensitivities, deliberately not one flag.
             *
             * Confidential restricts who may see it. Legally sensitive says the document is under
             * legal hold or privilege — it changes what may be done to it, not who may read it, and
             * in particular it must survive a routine deletion. Collapsing them into one flag loses
             * the distinction exactly when it matters.
             */
            $table->boolean('is_confidential')->default(false)->index();
            $table->boolean('is_legally_sensitive')->default(false)->index();
            $table->string('sensitivity_note')->nullable();

            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->unsignedInteger('version_count')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            // Soft delete first, so an accidental removal is recoverable; a permanent delete is a
            // separate permission and actually removes the bytes.
            $table->softDeletes();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'folder_id', 'status']);
        });

        // ── Every file that has ever held that place ─────────────────────────
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->unsignedInteger('version')->default(1);

            $table->string('original_name');          // what the uploader called it
            $table->string('stored_name');            // what we called it — never client-controlled
            $table->string('disk', 40)->default('documents');
            $table->string('path', 1000);
            $table->string('extension', 20)->nullable();
            // The type we determined by reading the file, not the one the browser claimed.
            $table->string('mime', 150)->nullable();
            $table->string('declared_mime', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            // Identical re-uploads are recognised rather than silently duplicated.
            $table->string('checksum', 64)->nullable()->index();

            $table->text('change_note')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'version']);
        });

        // ── What was done, and by whom ───────────────────────────────────────
        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->unsignedBigInteger('version_id')->nullable();
            // uploaded | viewed | downloaded | replaced | archived | restored | deleted | …
            $table->string('action', 30)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            // "Who has opened this?" is the first question asked of a confidential document, and it
            // has to be answerable without scanning the whole table.
            $table->index(['company_id', 'document_id', 'action']);
        });

        // ── What this tenant allows ──────────────────────────────────────────
        Schema::create('document_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->unique();
            $table->unsignedInteger('max_file_size_mb')->default(25);
            $table->unsignedSmallInteger('max_files_per_upload')->default(20);
            // Extensions rather than MIME types: it is what an administrator can reason about, and
            // the MIME is verified against the bytes separately.
            $table->json('allowed_extensions')->nullable();
            $table->json('blocked_extensions')->nullable();
            // Pattern for automatic naming, e.g. {folder}-{date}-{seq}
            $table->string('naming_pattern')->nullable();
            $table->boolean('auto_name')->default(false);
            $table->boolean('require_description')->default(false);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            $q = chr(39);
            $session = "NULLIF(current_setting({$q}app.company_id{$q}, true), {$q}{$q})";
            $policy = $session . ' IS NULL OR company_id = ' . $session . '::bigint';

            foreach ([
                'document_folders', 'documents', 'document_versions',
                'document_events', 'document_settings',
            ] as $t) {
                DB::statement("ALTER TABLE \"{$t}\" ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE \"{$t}\" ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE \"{$t}\" FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$t}\"");
                DB::statement("CREATE POLICY tenant_isolation ON \"{$t}\" USING ({$policy}) WITH CHECK ({$policy})");
            }

            // Search over title, description and notes without a separate index infrastructure.
            DB::statement("CREATE INDEX documents_search_idx ON documents USING gin (
                to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description, '') || ' ' || coalesce(notes, ''))
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_settings');
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_folders');
    }
};
