<?php

namespace App\Modules\Operations\ProjectManagement\Support\Providers;

/**
 * A document's bytes, with enough about them to serve a correct response.
 *
 * `mime` and `filename` travel with the payload because a download served without them is a
 * browser guessing, and a browser guessing on a 40 MB drawing usually guesses "show this as text".
 */
final class DocumentContents
{
    public function __construct(
        public readonly string $filename,
        public readonly string $bytes,
        public readonly ?string $mime = null,
    ) {
    }

    /**
     * Headers for an inline response.
     *
     * `nosniff` is not decoration: these bytes were uploaded by a user, and without it a file
     * named as a PDF but containing markup is content the browser may decide to execute in the
     * application's own origin.
     *
     * @return array<string,string>
     */
    public function headers(): array
    {
        return [
            'Content-Type'           => $this->mime ?: 'application/octet-stream',
            'Content-Length'         => (string) strlen($this->bytes),
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
