<?php

namespace App\Core\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Profile pictures, logos and signature images.
 *
 * These behave differently from documents and so are handled apart from StoresFileBytes. A document
 * is fetched once, deliberately, by someone who asked for it. An image is rendered as part of a
 * page, often two hundred at a time, and the way it was stored made every one of those an embedded
 * base64 blob in the response — a staff listing shipped the whole photo album inline whether or not
 * anyone scrolled to it.
 *
 * So the bytes move to the shared disk and the payload carries a URL. The field the frontend reads
 * keeps its name and its meaning, because `<img src>` accepts either form: nothing on the page has
 * to change, and the browser fetches only the images it actually shows, with normal caching.
 *
 * `url()` returns the legacy data URI when a record has not been migrated, so both states render.
 */
class StoredImage
{
    /**
     * Stores an image and returns the path to record.
     *
     * @param  string  $folder  where these images are filed on the shared disk
     */
    public static function put(UploadedFile $file, string $folder): string
    {
        $binary = file_get_contents($file->getRealPath());

        $extension = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $file->getClientOriginalExtension()) ?: 'jpg');

        // Generated, never taken from the upload: a client-supplied name is the usual route to
        // writing outside the intended directory.
        $path = trim($folder, '/') . '/' . Str::uuid() . '.' . $extension;

        Storage::disk('documents')->put($path, $binary);

        return $path;
    }

    /** Stores already-decoded bytes, for a signature drawn in the browser. */
    public static function putBytes(string $binary, string $folder, string $extension = 'png'): string
    {
        $path = trim($folder, '/') . '/' . Str::uuid() . '.'
            . Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $extension) ?: 'png');

        Storage::disk('documents')->put($path, $binary);

        return $path;
    }

    /**
     * What to put in a payload for an <img src>.
     *
     * A URL when the image is on the disk, the old data URI when it is still in a column, and null
     * when there is no image — which the frontend already handles, because there have always been
     * people without a photo.
     */
    public static function url(?string $path, mixed $legacy = null): ?string
    {
        if (! blank($path)) {
            return url('/dbfiles/documents/' . ltrim($path, '/'));
        }

        return self::legacyDataUri($legacy);
    }

    /**
     * The data URI for a record still holding its image in a column.
     *
     * The column holds the envelope the shared upload trait produces — bytes, mime and original
     * name — either as JSON text or already decoded by a jsonb cast, so both are accepted.
     */
    public static function legacyDataUri(mixed $legacy): ?string
    {
        if (blank($legacy)) {
            return null;
        }

        $envelope = is_string($legacy) ? json_decode($legacy, true) : (array) $legacy;

        if (! is_array($envelope) || blank($envelope['data'] ?? null)) {
            return null;
        }

        return 'data:' . ($envelope['mime'] ?? 'image/jpeg') . ';base64,' . $envelope['data'];
    }

    /** The raw bytes, wherever they are — for a PDF that draws the image rather than linking it. */
    public static function bytes(?string $path, mixed $legacy = null): ?string
    {
        if (! blank($path)) {
            try {
                $disk = Storage::disk('documents');

                if ($disk->exists($path)) {
                    return $disk->get($path);
                }
            } catch (\Throwable) {
                // Fall through to the column: a missing file is a reason to try the other copy.
            }
        }

        $envelope = is_string($legacy) ? json_decode((string) $legacy, true) : (array) $legacy;

        return is_array($envelope) && ! blank($envelope['data'] ?? null)
            ? base64_decode((string) $envelope['data'])
            : null;
    }

    /**
     * Takes a data URI submitted as a form field and stores it, returning the path.
     *
     * Logos arrive this way — the browser reads the file and posts it as a string — so there is no
     * UploadedFile to hand to put(). Anything that is not a data URI comes back untouched, because
     * these fields have also been used to hold a plain URL.
     *
     * @return array{0:?string,1:?string} the value to keep in the column, and the path
     */
    public static function fromDataUri(?string $value, string $folder): array
    {
        if (blank($value) || ! str_starts_with($value, 'data:')) {
            return [$value, null];
        }

        if (! preg_match('#^data:image/([a-z0-9.+-]+);base64,(.*)$#is', $value, $parts)) {
            return [$value, null];
        }

        $binary = base64_decode($parts[2], true);

        if ($binary === false || $binary === '') {
            return [$value, null];
        }

        $extension = strtolower($parts[1]) === 'jpeg' ? 'jpg' : strtolower($parts[1]);

        // Column cleared: keeping both would leave the old image to be served whenever the path
        // was missed, which is the confusing half-migrated state this avoids.
        return [null, self::putBytes($binary, $folder, $extension)];
    }
}
