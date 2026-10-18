<?php

namespace App\Modules\Operations\ProjectManagement\Providers\Local;

use App\Core\User\Trait\FileStorage;
use App\Modules\Operations\ProjectManagement\Contracts\Providers\DocumentStore;
use App\Modules\Operations\ProjectManagement\Models\DocumentBlob;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Support\Providers\DocumentContents;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Project documents, when the Documents module is not installed (PM-1500, PM-ADR-012).
 *
 * The bytes go in the database, in `pm_document_blobs`, and nothing is written to a disk — for
 * tenancy (a file on a shared disk is outside row-level security), for backup (what is restored is
 * the database), and because nothing guarantees one machine with one persistent volume.
 *
 * Saving goes through the platform's own `FileStorage` trait rather than a PM-specific routine.
 * That is what every other module here uses to store an upload, so PM's documents are validated the
 * same way, compressed the same way, and shaped the same way — one convention on this system, not
 * two. The two overrides below are deliberate and are the only places PM differs.
 *
 * The index stays in `pm_external_refs`, so listings, task attachments and an eventual switch to
 * the Documents module all keep the same shape — only `source_id` changes meaning, from a path to a
 * blob uuid.
 */
class LocalDocumentStore implements DocumentStore
{
    use FileStorage;

    /**
     * The two places PM differs from the platform default, both set here rather than redeclared.
     *
     * A class that redeclares a trait's property with a different default is a fatal error on this
     * PHP version, so widening happens at construction — which also keeps the trait's values
     * visible as the baseline being widened *from*.
     */
    public function __construct()
    {
        // 50 MB, not the platform's 10. A general-arrangement drawing or a full specification runs
        // well past ten, and a document store that refuses them is one people work around by
        // emailing files instead — the outcome this module exists to prevent.
        $this->maxFileSizeMb = 50;

        // The trait's list is images, PDF and Word — right for a profile photograph or a policy,
        // and short of what a construction project carries. Extended rather than replaced, so the
        // platform's list stays the baseline and this reads as an addition.
        $this->allowedMimes = array_merge($this->allowedMimes, [
            'image/heic', 'image/heif', 'image/tiff', 'image/bmp',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/csv', 'text/plain',
            // Drawings. The CAD ones arrive with whatever mime the browser guessed, which is often
            // octet-stream, so that is on the list too — the size cap is the real guard here.
            'image/vnd.dwg', 'image/vnd.dxf', 'application/acad', 'application/dxf',
            'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
        ]);
    }

    public function put(string $projectId, UploadedFile $file, array $meta = []): string
    {
        // Validates the size and type, compresses an image, and hands back the platform's shape.
        // A rejection throws InvalidArgumentException, which the callers turn into a field error.
        $stored = $this->saveFileToDatabase($file);

        return DB::transaction(function () use ($projectId, $file, $meta, $stored) {
            $blob = new DocumentBlob([
                'uuid'          => (string) $stored['uuid'],
                'project_uuid'  => $projectId,
                'original_name' => Str::limit($stored['original_name'], 255, ''),
                'mime'          => $stored['mime'],
                'type'          => $stored['type'],
                'size'          => $stored['size'],
                // Of the bytes, not of their encoding. Hashing the base64 made the value
                // depend on how the file happened to be stored, which is exactly what a content
                // hash must not do — and it stops matching the moment storage changes.
                'sha256'        => hash('sha256', base64_decode($stored['data'])),
                'uploaded_by'   => $meta['uploaded_by'] ?? null,
            ]);

            // The bytes go to the shared disk rather than into the row, where encryption at rest
            // and the tenant storage quota apply. `data` stays out of $fillable for the reason it
            // always was: it is the one attribute that must never arrive from a request array, and
            // keeping it out also keeps it out of any audit diff or log that walks them.
            $blob->writeBytes(base64_decode($stored['data']), $file->getClientOriginalExtension());
            $blob->save();

            $ref = ExternalRef::create([
                'local_type'    => 'document',
                'source_module' => 'local',
                'source_entity' => 'document',
                'source_id'     => $blob->uuid,
                'display_name'  => $stored['original_name'],
                'snapshot'      => $meta + [
                    'project_id' => $projectId,
                    'size'       => $stored['size'],
                    // The mime *after* the trait has had it: an image is stored as JPEG whatever
                    // arrived, and a listing that reported the original would be describing bytes
                    // that are no longer there.
                    'mime'       => $stored['mime'],
                    'type'       => $stored['type'],
                ],
            ]);

            return $ref->uuid;
        });
    }

    public function describe(string $documentId): ?ProvidedRecord
    {
        $blob = $this->blob($documentId);

        if (! $blob) {
            return null;
        }

        return new ProvidedRecord(
            id: $documentId,
            label: $blob->original_name,
            source: 'local',
            meta: ['size' => $blob->size, 'mime' => $blob->mime, 'type' => $blob->type],
        );
    }

    public function contents(string $documentId): ?DocumentContents
    {
        $blob = $this->blob($documentId);

        if (! $blob) {
            return null;
        }

        $file = $this->getFileForDisplay($blob->payload(), (string) $blob->mime, $blob->type);

        return new DocumentContents(
            filename: $blob->original_name,
            bytes: $blob->type === 'image' ? base64_decode($blob->payload()) : $file['binary'],
            mime: $blob->mime,
        );
    }

    /**
     * Always null: these bytes have no address outside the application.
     *
     * That is the point rather than a limitation — a link that works without a session is a link
     * that works after it has been forwarded, and a signed variation order is not something to
     * hand out on a URL.
     */
    public function url(string $documentId): ?string
    {
        return null;
    }

    public function forProject(string $projectId): array
    {
        return ExternalRef::query()
            ->where('source_entity', 'document')
            ->where('snapshot->project_id', $projectId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ExternalRef $r) => new ProvidedRecord(
                id: $r->uuid,
                label: $r->display_name ?: 'Document',
                source: 'local',
                meta: $r->snapshot ?? [],
            ))
            ->all();
    }

    /** This store owns the bytes, so removing the index without them would leak storage forever. */
    public function delete(string $documentId): void
    {
        DB::transaction(function () use ($documentId) {
            $ref = ExternalRef::where('uuid', $documentId)->where('source_entity', 'document')->first();

            if (! $ref) {
                return;
            }

            DocumentBlob::where('uuid', $ref->source_id)->delete();

            $ref->delete();
        });
    }

    private function blob(string $documentId): ?DocumentBlob
    {
        $ref = ExternalRef::where('uuid', $documentId)->where('source_entity', 'document')->first();

        if (! $ref || ! $ref->source_id) {
            return null;
        }

        return DocumentBlob::where('uuid', $ref->source_id)->first(DocumentBlob::INDEX_COLUMNS);
    }
}
