<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every file SHEQ holds — evidence photos, certificates, procedures, signatures (SHEQ-OFF-7/8).
 *
 * Stored in the database, base64, through `App\Core\User\Trait\FileStorage`, which is the platform's
 * existing convention (User profiles, Contract Management). Keeping SHEQ on the same trait means one
 * place decides compression, size limits and permitted types, and a change there reaches every
 * module at once.
 *
 * One table rather than a JSON column on each parent, because SHEQ evidence attaches to six
 * different subjects — an incident, a hazard, an inspection, a contractor's compliance document, a
 * competency certificate, a document version — and the same questions get asked of all of them:
 * what is still uploading, what is missing, what proves this record. Six JSON columns would be six
 * implementations of those queries.
 *
 * The upload state is the load-bearing part. SHEQ-OFF-7 requires record sync never to block on
 * media and a record with pending media to show that plainly, so a row exists from the moment a
 * device announces a file — before a byte arrives. A record whose evidence is mandatory cannot be
 * closed while any of its files are still PENDING.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // INCIDENT|HAZARD|INSPECTION|CONTRACTOR_DOCUMENT|DOCUMENT_VERSION|COMPETENCY|PERMIT|ACTION
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            // EVIDENCE|CERTIFICATE|PROCEDURE|SIGNATURE|STATEMENT|OTHER
            $table->string('category', 30)->default('EVIDENCE');
            $table->string('caption')->nullable();

            // ── The FileStorage trait's own shape, column for column. Storing its output under
            //    different names would make the two drift the first time the trait changes.
            $table->string('original_name');
            $table->string('mime', 120)->nullable();
            $table->string('type', 20)->nullable();          // image|document
            $table->unsignedBigInteger('size')->default(0);
            // base64. Nullable because the row is created when the upload is announced, and the
            // bytes arrive afterwards — possibly much later, from a device with no signal.
            $table->longText('data')->nullable();

            /**
             * Integrity and deduplication. A device that retries an upload sends the same bytes;
             * matching the hash means the second attempt is recognised rather than stored twice —
             * which matters when the payload is base64 in a row.
             */
            $table->char('sha256', 64)->nullable();

            /**
             * SHEQ-OFF-8: the EXIF timestamp and GPS are preserved even though the image itself is
             * recompressed. They are the evidentiary part — when and where the photograph was taken
             * is what makes it evidence rather than a picture, and stripping it during compression
             * is the quiet way to destroy that.
             */
            $table->timestampTz('captured_at')->nullable();
            $table->decimal('captured_lat', 10, 7)->nullable();
            $table->decimal('captured_lng', 10, 7)->nullable();
            $table->jsonb('exif')->nullable();

            // PENDING|STORED|FAILED — never "assumed uploaded" (SHEQ-OFF-9: never fake success).
            $table->string('upload_status', 20)->default('PENDING');
            $table->unsignedSmallInteger('chunks_received')->default(0);
            $table->unsignedSmallInteger('chunks_expected')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('stored_at')->nullable();

            // The device's own id, so a resumed or retried upload is the same file (SHEQ-OFF-2).
            $table->uuid('client_id')->nullable();
            $table->string('device_id', 100)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'client_id'], 'sheq_files_client_uniq');
            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'upload_status']);
        });

        /**
         * Chunks land here until the file is whole (SHEQ-OFF-7: resumable, chunked, survives an app
         * kill). Held apart from the file row so a half-received upload never looks like a stored
         * one, and so the parts can be dropped the moment they are assembled.
         */
        Schema::create('sheq_file_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('sheq_files')->cascadeOnDelete();

            $table->unsignedSmallInteger('sequence');
            $table->longText('data');                         // base64 slice
            $table->unsignedInteger('size')->default(0);

            $table->timestamps();

            // A device that resends a chunk after a dropped connection must not append it twice.
            $table->unique(['file_id', 'sequence'], 'sheq_file_chunk_uniq');
        });

        $this->applyTenantRls(['sheq_files', 'sheq_file_chunks']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_file_chunks');
        Schema::dropIfExists('sheq_files');
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
