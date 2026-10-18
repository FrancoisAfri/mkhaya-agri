<?php

namespace App\Core\Storage;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a file stored in the database (tenant_files). RLS scopes the lookup to the current
 * company, so a tenant can only fetch its own files.
 *
 * The bytes are decrypted through the same service that wrote them. This route reads the table
 * directly rather than going through the disk, so it has to decrypt explicitly — which is precisely
 * why it is worth naming: a second path to the bytes is a second place for a guarantee to be missed.
 */
class StoredFileController extends Controller
{
    public function show(Request $request, string $disk, string $path): Response
    {
        $row = DB::table('tenant_files')->where('disk', $disk)->where('path', $path)->first();
        abort_if(! $row, 404);

        $stored = is_resource($row->contents) ? stream_get_contents($row->contents) : (string) $row->contents;

        try {
            $bytes = app(DocumentEncryption::class)->decrypt($stored);
        } catch (\Throwable $e) {
            report($e);
            abort(500, 'This file could not be decrypted.');
        }

        /*
         * Served as a download with the type declared inert.
         *
         * This route previously echoed the file's own MIME type inline with no `nosniff`, which
         * turns any uploaded HTML or SVG into stored cross-site scripting: the browser renders it
         * same-origin with the application, where it can read the session. Images are the one type
         * allowed to render inline, because that is what the route is used for and an image cannot
         * carry script — SVG excepted, which is why it is not on the list.
         */
        $inlineTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];
        $isImage = in_array((string) $row->mime, $inlineTypes, true);

        // Rebuilt, never echoed: a filename carrying a quote or newline can otherwise close the
        // header and set ones we did not intend. addslashes(), which was here before, does not
        // stop that.
        $name = Str::of($row->name ?: basename($path))
            ->replaceMatches('/[^\w.\- ]+/u', '_')->limit(200, '')->trim()->value() ?: 'file';

        return response($bytes, 200, [
            'Content-Type'           => $isImage ? $row->mime : 'application/octet-stream',
            'Content-Length'         => (string) strlen($bytes),
            'Content-Disposition'    => ($isImage ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            // These are tenant documents. A shared cache holding them is a cross-tenant leak that
            // never touches the application.
            'Cache-Control'          => 'private, no-store, max-age=0',
        ]);
    }
}
