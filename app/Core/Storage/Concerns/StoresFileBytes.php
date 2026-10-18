<?php

namespace App\Core\Storage\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gives a model that owns a file one way to read and write its bytes.
 *
 * Around twenty tables across the platform each grew their own base64 blob column, and with them
 * roughly forty-eight places that decode one and hand it to a browser. That is why encryption,
 * malware scanning and streaming had nowhere to live: there was no single point every file passed
 * through, so every improvement meant forty-eight edits and forty-eight chances to miss one.
 *
 * A model using this declares which columns it has, and then reads through `bytes()` and writes
 * through `writeBytes()`. Both go to the shared `documents` disk, where encryption at rest and the
 * quota already apply — so a module adopting this inherits them without knowing they exist.
 *
 * Reads check the path first and fall back to the blob column. That fallback is what allows this to
 * be adopted one module at a time and deployed before any data is migrated: a row that
 * `storage:consolidate` has moved and a row still holding its original bytes both work, and pruning
 * the old column afterwards is invisible to readers.
 *
 * A model declares its shape by overriding the three methods below when its columns differ from the
 * defaults, which most do — these tables were written independently and agree on almost nothing.
 */
trait StoresFileBytes
{
    /** The legacy base64 column, or null once a table has been pruned. */
    protected function fileBlobColumn(): ?string
    {
        return 'data';
    }

    /** The column holding the path on the shared disk. */
    protected function filePathColumn(): string
    {
        return 'storage_path';
    }

    /** Where this model's files are filed on that disk. */
    protected function fileFolder(): string
    {
        return 'files';
    }

    /**
     * The bytes behind this record, wherever they currently live.
     *
     * Returns null rather than throwing when there is nothing to read. A missing file is a normal
     * state for a record whose upload failed halfway, and callers already have to render that.
     */
    public function bytes(): ?string
    {
        return $this->diskBytes() ?? $this->legacyBytes();
    }

    /**
     * The bytes from the shared disk, or null if this record has no path or the file is gone.
     *
     * Split out from bytes() so a model with its own reading rules can compose the two halves
     * rather than reimplement either — Project Management's blob deliberately re-reads its legacy
     * column from the database instead of holding it on the model, and needs to keep doing that.
     */
    protected function diskBytes(): ?string
    {
        $path = $this->filePathColumn();

        if (blank($this->{$path} ?? null)) {
            return null;
        }

        try {
            $disk = Storage::disk('documents');

            if ($disk->exists($this->{$path})) {
                return $disk->get($this->{$path});
            }

            // The pointer is set but the file is gone. Worth a log line: it means either a failed
            // write or something deleted from under us, and both are worth knowing before the blob
            // fallback quietly papers over it.
            Log::warning('Stored file missing from disk.', [
                'model' => static::class, 'id' => $this->getKey(), 'path' => $this->{$path},
            ]);
        } catch (\Throwable $e) {
            Log::warning('Stored file could not be read.', [
                'model' => static::class, 'id' => $this->getKey(), 'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /** The bytes still held in the original column, for a record nothing has migrated yet. */
    protected function legacyBytes(): ?string
    {
        $blob = $this->fileBlobColumn();

        if ($blob === null || blank($this->{$blob} ?? null)) {
            return null;
        }

        return $this->decodeLegacyBlob((string) $this->{$blob});
    }

    /**
     * Reads whatever historically ended up in the blob column.
     *
     * Three shapes exist across the platform, because these tables were written independently and
     * two different helpers were in use:
     *
     *   a JSON envelope — {"data": "<base64>", "mime": ..., "original_name": ...}, produced by the
     *                     shared FileStorage trait and stored whole by several modules
     *   plain base64    — the more common case
     *   raw binary      — rows written by code that skipped the encode
     *
     * Distinguishing them is safe rather than clever: base64 has no `{`, so a leading brace can
     * only be the envelope. The raw-binary case is a strict decode that fails, and it is treated as
     * bytes rather than as an error, because returning null there would lose a file that is
     * perfectly intact.
     */
    protected function decodeLegacyBlob(string $raw): ?string
    {
        if (str_starts_with(ltrim($raw), '{')) {
            $envelope = json_decode($raw, true);

            if (is_array($envelope) && isset($envelope['data'])) {
                $inner = base64_decode((string) $envelope['data'], true);

                return $inner === false ? null : $inner;
            }
        }

        $decoded = base64_decode($raw, true);

        return $decoded === false ? $raw : $decoded;
    }

    /**
     * Writes bytes to the shared disk and sets the path column. Does not save the model.
     *
     * The path is generated from a uuid rather than the uploaded name: a client-supplied filename
     * is the usual route to writing outside the intended directory, and the original belongs in a
     * label column, not in a path.
     */
    public function writeBytes(string $binary, ?string $extension = null): string
    {
        $extension = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', (string) $extension) ?: 'bin');

        $path = trim($this->fileFolder(), '/') . '/' . Str::uuid() . '.' . $extension;

        Storage::disk('documents')->put($path, $binary);

        $this->{$this->filePathColumn()} = $path;

        return $path;
    }

    /**
     * Removes the file this record points at.
     *
     * Deliberately not hooked to model deletion. Several of these tables soft-delete, and a few
     * keep history by pointing more than one row at the same path — deciding that here would make
     * an assumption on behalf of modules that have not been looked at yet.
     */
    public function deleteBytes(): void
    {
        $path = $this->{$this->filePathColumn()} ?? null;

        if (blank($path)) {
            return;
        }

        try {
            Storage::disk('documents')->delete($path);
        } catch (\Throwable $e) {
            Log::warning('Stored file could not be deleted.', [
                'model' => static::class, 'id' => $this->getKey(), 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Whether there are bytes to be had at all, without loading them. */
    public function hasBytes(): bool
    {
        $blob = $this->fileBlobColumn();

        return ! blank($this->{$this->filePathColumn()} ?? null)
            || ($blob !== null && ! blank($this->{$blob} ?? null));
    }

    /**
     * Serves this record's file.
     *
     * Every table that grew a blob column also grew its own download method, and almost all of them
     * made the same three mistakes: the file's own MIME type, no `nosniff`, and the stored filename
     * interpolated straight into the header. Together those are stored cross-site scripting — a
     * browser handed a type it recognises may render the file, and an uploaded HTML page then runs
     * as this application's origin, with access to the session that opened it.
     *
     * Putting the response here means a module gets this right by adopting the trait, rather than by
     * each author remembering.
     *
     * `$inline` is honoured only for types that cannot carry script, because inline display is the
     * whole risk. A PDF or an image previews; everything else downloads, whatever was asked for.
     */
    public function fileResponse(?string $fileName = null, ?string $mime = null, bool $inline = false): \Illuminate\Http\Response
    {
        $bytes = $this->bytes();

        abort_if($bytes === null, 404, 'This file is no longer available.');

        $mime = $mime ?: 'application/octet-stream';
        $renderable = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];
        $inline = $inline && in_array($mime, $renderable, true);

        // Rebuilt rather than echoed: a name carrying a quote or a newline can otherwise close the
        // header and set ones we did not intend.
        $safe = \Illuminate\Support\Str::of((string) ($fileName ?: 'document'))
            ->replaceMatches('/[^\w.\- ]+/u', '_')->limit(200, '')->trim()->value() ?: 'document';

        return response($bytes, 200, [
            'Content-Type'           => $inline ? $mime : 'application/octet-stream',
            'Content-Length'         => (string) strlen($bytes),
            'Content-Disposition'    => ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"',
            'X-Content-Type-Options' => 'nosniff',
            // Tenant documents. A shared cache holding one is a cross-tenant leak that never
            // touches the application.
            'Cache-Control'          => 'private, no-store, max-age=0',
        ]);
    }

    /**
     * A field from the legacy JSON envelope, for rows whose own columns were never filled.
     *
     * Several modules stored the whole array the shared FileStorage trait returned, so the name and
     * type of those files live inside the blob rather than in columns of their own.
     */
    protected function legacyEnvelopeValue(string $key): ?string
    {
        $blob = $this->fileBlobColumn();

        if ($blob === null || blank($this->{$blob} ?? null)) {
            return null;
        }

        $raw = (string) $this->{$blob};

        if (! str_starts_with(ltrim($raw), '{')) {
            return null;
        }

        $envelope = json_decode($raw, true);

        return is_array($envelope) && isset($envelope[$key]) ? (string) $envelope[$key] : null;
    }
}
