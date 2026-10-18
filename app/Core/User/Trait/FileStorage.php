<?php

namespace App\Core\User\Trait;

use App\Core\Storage\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores an uploaded file as a database-ready array.
 *
 * Used by roughly twenty callers across profiles, contracts, tenders, policies and conferences, so
 * the shape it returns is fixed. Everything below changes how the bytes are handled on the way
 * through, not what comes out.
 */
trait FileStorage
{
    protected int   $imageQuality  = 60;
    protected int   $imageMaxWidth = 1920;
    protected int   $maxFileSizeMb = 10;

    protected array $allowedMimes = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /*
    |--------------------------------------------------------------------------
    | 1. SAVE — returns an array ready to insert
    |--------------------------------------------------------------------------
    */
    public function saveFileToDatabase(UploadedFile $file): array
    {
        $this->validateFile($file);

        $mime = $this->normalisedMime($file);
        $type = str_starts_with($mime, 'image/') ? 'image' : 'document';

        if ($type === 'image') {
            [$binary, $mime] = $this->processImage($file, $mime);
        } else {
            $binary = file_get_contents($file->getRealPath());
        }

        return [
            'uuid'          => Str::uuid(),
            'original_name' => $file->getClientOriginalName(),
            // The type the stored bytes actually are. This used to be hard-coded to image/jpeg for
            // every image, which was a lie for anything the re-encode left alone.
            'mime'          => $mime,
            'type'          => $type,
            'data'          => base64_encode($binary),
            'size'          => strlen($binary),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 2. READ — for frontend display (image) or download (document)
    |--------------------------------------------------------------------------
    */
    public function getFileForDisplay(string $base64Data, string $mime, string $type): string|array
    {
        $binary = base64_decode($base64Data);

        if ($type === 'image') {
            return [
                'url'  => 'data:' . $mime . ';base64,' . $base64Data,
                'size' => strlen($binary),
            ];
        }

        return [
            'binary' => $binary,
            'mime'   => $mime,
            'size'   => strlen($binary),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | 3. DOWNLOAD RESPONSE — called from the controller
    |--------------------------------------------------------------------------
    */
    public function downloadResponse(string $base64Data, string $mime, string $originalName): \Illuminate\Http\Response
    {
        $binary = base64_decode($base64Data);

        // Rebuilt rather than interpolated. A name carrying a quote or a newline can otherwise
        // close the header and set others; addslashes, the usual reflex, does not prevent it.
        $safeName = Str::of($originalName)->replaceMatches('/[^\w.\- ]+/u', '_')->limit(200, '')->trim()->value()
            ?: 'download';

        return response($binary, 200, [
            // Never the file's own type on a download. A browser handed a type it recognises may
            // render it, and an uploaded HTML page would then run as this application's origin,
            // with access to the session that opened it.
            'Content-Type'           => 'application/octet-stream',
            'Content-Length'         => (string) strlen($binary),
            'Content-Disposition'    => 'attachment; filename="' . $safeName . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store, max-age=0',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Private — image handling
    |--------------------------------------------------------------------------
    */

    /**
     * Resizes an oversized image, keeping the format it arrived in.
     *
     * This used to re-encode every image as JPEG. Two things were wrong with that. A PNG with
     * transparency came back with a black or white background — the alphablending calls before it
     * could not help, because JPEG has no alpha channel to save into. And an animated GIF was
     * flattened to its first frame, since imagecreatefromstring only ever reads one.
     *
     * The file a user uploaded is also the file they expect back. Re-encoding it is a decision that
     * needs a reason, and "it might be smaller" is not one when the cost is losing what the image
     * was for. So: images within the width limit are stored untouched, oversized ones are resized
     * in their own format, and GIFs are left alone entirely because there is no way to resize one
     * here without discarding the animation.
     *
     * @return array{0:string,1:string} bytes and the mime they are now
     */
    private function processImage(UploadedFile $file, string $mime): array
    {
        $binary = file_get_contents($file->getRealPath());

        // An animated GIF cannot survive this, and a still one is small enough not to matter.
        if ($mime === 'image/gif') {
            return [$binary, $mime];
        }

        $source = @imagecreatefromstring($binary);

        // Not something GD understands — store exactly what arrived rather than mangling it.
        if ($source === false) {
            return [$binary, $mime];
        }

        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= $this->imageMaxWidth) {
            imagedestroy($source);

            // Already a reasonable size. Leaving it be preserves the original quality, and for
            // formats that are already compressed a re-encode usually makes the file bigger.
            return [$binary, $mime];
        }

        $newWidth = $this->imageMaxWidth;
        $newHeight = (int) round($height * ($this->imageMaxWidth / $width));
        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Transparency has to be preserved on the canvas before anything is copied onto it, and it
        // only holds for a format that can store it — which is the whole reason for keeping PNG
        // and WebP as themselves below.
        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        ob_start();
        match ($mime) {
            // 9 is maximum lossless compression, not quality loss — PNG has no lossy mode.
            'image/png'  => imagepng($resized, null, 9),
            'image/webp' => imagewebp($resized, null, max(1, $this->imageQuality)),
            default      => imagejpeg($resized, null, $this->imageQuality),
        };
        $out = (string) ob_get_clean();
        imagedestroy($resized);

        if ($out === '') {
            // The encoder failed. The original is still good, and storing it is better than
            // storing nothing.
            return [$binary, $mime];
        }

        return [$out, $mime];
    }

    /*
    |--------------------------------------------------------------------------
    | Private — validation
    |--------------------------------------------------------------------------
    */

    private function validateFile(UploadedFile $file): void
    {
        throw_if(
            $file->getSize() > $this->maxFileSizeMb * 1024 * 1024,
            \InvalidArgumentException::class,
            "File too large (maximum {$this->maxFileSizeMb} MB)"
        );

        $mime = $this->normalisedMime($file);

        throw_unless(
            in_array($mime, $this->allowedMimes, true),
            \InvalidArgumentException::class,
            "File type not allowed: {$mime}"
        );

        // An allow-list establishes that the bytes are a type we accept. It says nothing about
        // whether they are safe — the server never runs an uploaded file, but the colleague who
        // opens it does, and this is what handed it to them.
        $threat = app(MalwareScanner::class)->scan($file);

        throw_if($threat !== null, \InvalidArgumentException::class, (string) $threat);
    }

    /**
     * The type read from the file's own bytes.
     *
     * getMimeType() sniffs the contents rather than trusting the upload's declared type, which is
     * the important part; getClientMimeType() is caller-controlled and is not used here. The
     * normalisation exists because some clients send image/jpg, which is not a real media type.
     */
    private function normalisedMime(UploadedFile $file): string
    {
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');

        return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
    }
}
