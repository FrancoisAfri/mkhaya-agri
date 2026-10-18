<?php

namespace App\Modules\ESign\Services;

use App\Modules\ESign\Models\Envelope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The document an envelope is about: storing it, reading it back, and recording what was signed.
 *
 * Kept apart from ESignService, which owns the lifecycle — status, tokens, invitations, audit.
 * This owns bytes. The two have different failure modes and different things worth testing.
 *
 * ── Where the bytes live ─────────────────────────────────────────────────────
 *
 * On the configured disk, which on this platform is a database driver writing into `tenant_files`.
 * That matters in one specific way: `Storage::path()` returns a string that resolves to nothing,
 * so anything reaching for a filesystem path — file_get_contents, readfile, a PDF library given a
 * path — silently reads nothing at all. Everything here goes through the disk's own API.
 */
class EnvelopeDocumentService
{
    /** Anything larger is a scan nobody will read, and a browser render nobody will wait for. */
    public const MAX_KILOBYTES = 20480;

    public function disk(Envelope $envelope): string
    {
        return $envelope->disk ?: config('filesystems.default', 'local');
    }

    /**
     * Store the document this envelope is for.
     *
     * The hash is taken from the bytes as stored, not from the upload: it is what a recipient will
     * later check the executed copy against, and the two have to be the same file.
     */
    public function store(Envelope $envelope, UploadedFile $file): Envelope
    {
        $disk  = $this->disk($envelope);
        $bytes = $file->get();
        $path  = "esign/{$envelope->id}/source_{$envelope->uuid}.pdf";

        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']);

        $envelope->forceFill([
            'disk'          => $disk,
            'document_path' => $path,
            'document_hash' => hash('sha256', $bytes),
        ])->save();

        return $envelope;
    }

    /**
     * The current document: the executed copy once it exists, otherwise the original.
     *
     * Signing is sequential, and each signer stamps the copy the one before them produced. So "the
     * document" is whatever the last signature left behind — asking for the original mid-way would
     * hand the next signer a page with the previous signature missing, and they would sign a
     * different document from the one their colleague signed.
     */
    public function currentBytes(Envelope $envelope): ?string
    {
        $path = $envelope->executed_document_path ?: $envelope->document_path;

        if (! $path) {
            return null;
        }

        $disk = $this->disk($envelope);

        return Storage::disk($disk)->exists($path) ? Storage::disk($disk)->get($path) : null;
    }

    public function hasDocument(Envelope $envelope): bool
    {
        return (bool) ($envelope->document_path || $envelope->executed_document_path);
    }

    /**
     * Record the copy a signer has just signed.
     *
     * Overwrites the running executed copy rather than keeping one file per signer: the whole point
     * of stamping in sequence is that there is one document gathering signatures, and a folder of
     * partially-signed variants invites somebody to circulate the wrong one.
     */
    public function storeExecuted(Envelope $envelope, string $bytes): Envelope
    {
        $disk = $this->disk($envelope);
        $path = "esign/{$envelope->id}/executed_{$envelope->uuid}.pdf";

        Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private']);

        $envelope->forceFill([
            'disk'                   => $disk,
            'executed_document_path' => $path,
            'executed_hash'          => hash('sha256', $bytes),
        ])->save();

        return $envelope;
    }
}
