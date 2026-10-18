<?php

namespace App\Modules\Operations\DocumentManagement\Concerns;

use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Turning a stored version into an HTTP response, with the headers that keep it from becoming an
 * attack on the person reading it.
 *
 * Shared between the authenticated download and the public share link deliberately. Two copies of
 * this would eventually differ, and the copy that lost `nosniff` or the sandbox would be the public
 * one — the route with no session behind it, serving files uploaded by somebody else.
 */
trait ServesDocumentBytes
{
    protected function streamVersion(
        DocumentService $documents,
        DocumentVersion $version,
        bool $inline,
    ): Response {
        $bytes = $documents->contents($version);
        abort_if($bytes === null, 404, 'The file for this version is no longer available.');

        return $this->streamBytes($bytes, $version, $inline);
    }

    /**
     * The same response, for bytes the caller already has.
     *
     * Exists so a watermarked copy goes out under exactly the headers an ordinary download does.
     * A stamped derivative served through a second, simpler path is how the one file most likely
     * to be forwarded ends up as the one without nosniff on it.
     */
    protected function streamBytes(string $bytes, DocumentVersion $version, bool $inline): Response
    {

        // The name is rebuilt rather than echoed: a filename with quotes or newlines in it can
        // otherwise break out of the header and set ones we did not intend.
        $safeName = Str::of($version->original_name)
            ->replaceMatches('/[^\w.\- ]+/u', '_')
            ->limit(200, '')
            ->trim()
            ->value() ?: ('document.' . $version->extension);

        return response($bytes, 200, [
            'Content-Type'            => $inline ? $version->mime : 'application/octet-stream',
            'Content-Length'          => (string) strlen($bytes),
            'Content-Disposition'     => ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"',
            'X-Content-Type-Options'  => 'nosniff',
            // A private document must not sit in a shared cache after the session ends. On a shared
            // link this matters more, not less: the request may pass through a proxy nobody here
            // controls.
            'Cache-Control'           => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            'X-Frame-Options'         => 'SAMEORIGIN',
        ]);
    }
}
