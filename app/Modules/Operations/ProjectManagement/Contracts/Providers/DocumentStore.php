<?php

namespace App\Modules\Operations\ProjectManagement\Contracts\Providers;

use App\Modules\Operations\ProjectManagement\Support\Providers\DocumentContents;
use App\Modules\Operations\ProjectManagement\Support\Providers\ProvidedRecord;
use Illuminate\Http\UploadedFile;

/**
 * Where project documents live. Local: the pm_document_blobs table. Integrated: the Documents
 * module, which holds its own bytes and hands back a link.
 *
 * Two ways to read a document, and a store implements whichever it can honestly offer:
 *
 *   contents()  the store holds the bytes and can give them over — PM streams them itself, under
 *               its own authorisation, with no URL to leak.
 *   url()       the store owns the bytes elsewhere and mints a time-limited link.
 *
 * Callers try `contents()` first and fall back to `url()`. A store returning null from both is one
 * that has lost the document, and the caller says so rather than serving an empty file.
 */
interface DocumentStore
{
    /**
     * Store an upload.
     *
     * The whole `UploadedFile` rather than its bytes, because validating the type and compressing
     * an image both need the real file — and both belong to the store, not to whichever screen
     * happened to receive the upload.
     *
     * @param  array<string,mixed>  $meta
     *
     * @throws \InvalidArgumentException when the file is too large or of a type this store refuses
     */
    public function put(string $projectId, UploadedFile $file, array $meta = []): string;

    /**
     * What the store now holds, without the payload.
     *
     * Needed because a store may change a file on the way in — the local one recompresses images —
     * so the size and mime the browser sent are not the size and mime of what is held. Callers that
     * only want to describe a document must not have to load it to do so.
     */
    public function describe(string $documentId): ?ProvidedRecord;

    /** The bytes, when this store holds them. */
    public function contents(string $documentId): ?DocumentContents;

    /** A time-limited link, when the bytes live in another system. */
    public function url(string $documentId): ?string;

    /** @return list<ProvidedRecord> */
    public function forProject(string $projectId): array;

    /**
     * Remove the document.
     *
     * A store that does not own its bytes may do nothing here — deleting them would be deleting
     * another module's record on a guess — but a store that does own them must actually delete,
     * otherwise "removed" means "hidden from this one listing".
     */
    public function delete(string $documentId): void;
}
