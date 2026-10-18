<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Project documents live in the database, not on a disk (EPIC-050, PM-ADR-012).
 *
 * The previous LocalDocumentStore wrote files to the `local` disk and kept only an index row. That
 * has three problems this platform cannot carry:
 *
 *  1. Tenancy. Every pm_* table is protected by FORCE ROW LEVEL SECURITY. A file on a shared disk
 *     has no such protection — the isolation stopped at the index and the bytes sat in a directory
 *     any code with a path could read.
 *  2. Backup. The database is what gets backed up and restored. A restore that brings back the
 *     index of a drawing without the drawing is a restore that quietly loses documents.
 *  3. Deployment. Nothing here is guaranteed to run on one machine with one persistent volume.
 *
 * The column shape is the platform's, not one of PM's own invention: `App\Core\User\Trait\FileStorage`
 * is how every other module on this system stores an uploaded file, and it writes
 * `uuid / original_name / mime / type / data / size` with the payload base64-encoded in `data`.
 * Matching it means PM's documents can be read by the same helpers, backed up by the same job and
 * understood by the next person without a second convention to learn.
 *
 * The migration also carries across anything the disk-backed store already wrote, and removes it
 * from the disk afterwards, so no existing upload is orphaned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_document_blobs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            // The project's public uuid, not its bigint id: the store is addressed the way the
            // rest of the module addresses things (PM-ADR-003), and it holds task attachments too,
            // which reach it through the same seam.
            $t->uuid('project_uuid')->nullable()->index();
            // ── The FileStorage shape ──────────────────────────────────────────
            $t->string('original_name', 255);
            $t->string('mime', 150)->nullable();
            $t->string('type', 20)->default('document');   // image | document
            $t->longText('data');                          // base64, as the trait writes it
            $t->unsignedBigInteger('size')->default(0);
            // ──────────────────────────────────────────────────────────────────
            // Content-addressed enough to spot a duplicate upload and to prove on restore that the
            // bytes are the bytes that went in. Not part of the trait; cheap and worth having.
            $t->string('sha256', 64)->nullable()->index();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        $this->applyTenantRls(['pm_document_blobs']);

        $this->carryAcrossExistingFiles();
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_document_blobs');
    }

    /**
     * Move whatever the disk-backed store already wrote into the table.
     *
     * Best-effort by design: a missing file is skipped and its index row is left pointing nowhere,
     * exactly as it already did. Failing the migration over a file somebody deleted by hand would
     * block the deployment for no gain.
     */
    private function carryAcrossExistingFiles(): void
    {
        if (! Schema::hasTable('pm_external_refs')) {
            return;
        }

        $refs = DB::table('pm_external_refs')
            ->where('source_entity', 'document')
            ->where('source_module', 'local')
            ->whereNotNull('source_id')
            ->get(['id', 'company_id', 'source_id', 'display_name', 'snapshot']);

        foreach ($refs as $ref) {
            // Only paths the old store minted; anything else is already somebody else's id.
            if (! str_starts_with((string) $ref->source_id, 'pm/documents/')) {
                continue;
            }

            try {
                if (! Storage::disk('local')->exists($ref->source_id)) {
                    continue;
                }

                $contents = Storage::disk('local')->get($ref->source_id);
            } catch (\Throwable) {
                continue;
            }

            $snapshot = json_decode((string) $ref->snapshot, true) ?: [];
            $uuid = (string) Str::uuid();
            $mime = $snapshot['mime'] ?? null;

            DB::table('pm_document_blobs')->insert([
                'uuid'          => $uuid,
                'company_id'    => $ref->company_id,
                'project_uuid'  => $snapshot['project_id'] ?? null,
                'original_name' => $ref->display_name ?: basename($ref->source_id),
                'mime'          => $mime,
                'type'          => $mime && str_starts_with($mime, 'image/') ? 'image' : 'document',
                // Carried across verbatim rather than recompressed: an old upload is evidence of
                // what was uploaded, and re-encoding somebody's site photograph during a migration
                // is a change to the record.
                'data'          => base64_encode($contents),
                'size'          => strlen($contents),
                'sha256'        => hash('sha256', $contents),
                'uploaded_by'   => $snapshot['uploaded_by'] ?? null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // The index now points at the blob rather than at a path.
            DB::table('pm_external_refs')->where('id', $ref->id)->update(['source_id' => $uuid]);

            Storage::disk('local')->delete($ref->source_id);
        }

        // The per-project directories the old store created, now empty.
        foreach (Storage::disk('local')->directories('pm/documents') as $directory) {
            if (Storage::disk('local')->files($directory) === []) {
                Storage::disk('local')->deleteDirectory($directory);
            }
        }
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
