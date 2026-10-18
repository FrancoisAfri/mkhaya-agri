<?php

namespace App\Modules\Operations\Sheq\Support;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

/**
 * Unpack an uploaded zip into the files it holds.
 *
 * A contractor's safety file arrives as one zip: the letter of good standing, the insurance
 * schedule, the section 37(2) agreement and the method statements, in one attachment. Making
 * somebody extract it and upload eight files by hand is how a compliance file ends up half
 * captured.
 *
 * ── What this refuses, and why ───────────────────────────────────────────────
 *
 * A zip is attacker-controlled input even when the attacker is a contractor's admin assistant who
 * downloaded it from somewhere:
 *
 * - **Zip slip.** An entry named `../../../.env` writes outside the directory it was unpacked
 *   into. Only the basename is ever used, so a path in an entry name is discarded rather than
 *   honoured.
 * - **Zip bombs.** A few kilobytes can expand to gigabytes. Both the entry count and the
 *   compression ratio are capped, and the ratio is checked from the archive's own index before
 *   anything is written.
 * - **Nested archives.** Not expanded. A zip inside a zip is stored as a file like any other,
 *   because recursion is where the bomb limits stop being limits.
 */
class UploadedArchive
{
    /** Beyond this, somebody is uploading a backup rather than a compliance file. */
    private const MAX_ENTRIES = 200;

    /** 25 MB per file, matching what a single upload is allowed. */
    private const MAX_ENTRY_BYTES = 26_214_400;

    /** Declared-to-compressed ratio above which the archive is refused outright. */
    private const MAX_RATIO = 1000;

    public static function isArchive(UploadedFile $file): bool
    {
        return strtolower($file->getClientOriginalExtension()) === 'zip'
            || $file->getMimeType() === 'application/zip';
    }

    /**
     * Expand into temporary files the caller then stores and discards.
     *
     * @return list<array{path:string, name:string}>
     *
     * @throws RuntimeException when the archive cannot be read or breaches a limit
     */
    public static function expand(UploadedFile $file): array
    {
        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath(), ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('That zip file could not be opened. Upload the files directly instead.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException(sprintf(
                    'That archive holds %d files. Up to %d can be unpacked at once.',
                    $zip->numFiles, self::MAX_ENTRIES,
                ));
            }

            $extracted = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false || str_ends_with($stat['name'], '/')) {
                    continue;   // a directory entry holds nothing
                }

                // Checked from the index, before a single byte is written anywhere.
                if ($stat['size'] > self::MAX_ENTRY_BYTES) {
                    throw new RuntimeException(sprintf(
                        '%s is larger than the %d MB limit for one file.',
                        basename($stat['name']), (int) (self::MAX_ENTRY_BYTES / 1_048_576),
                    ));
                }

                if ($stat['comp_size'] > 0 && ($stat['size'] / $stat['comp_size']) > self::MAX_RATIO) {
                    throw new RuntimeException(
                        'That archive expands far beyond its size and was not unpacked.'
                    );
                }

                // Basename only: a path in an entry name is an attempt to write elsewhere.
                $name = basename(str_replace('\\', '/', $stat['name']));

                if ($name === '' || str_starts_with($name, '.')) {
                    continue;   // hidden and resource-fork entries are noise, not documents
                }

                $contents = $zip->getFromIndex($i);

                if ($contents === false) {
                    continue;
                }

                $temp = tempnam(sys_get_temp_dir(), 'sheq-zip-');
                file_put_contents($temp, $contents);

                $extracted[] = ['path' => $temp, 'name' => $name];
            }

            if ($extracted === []) {
                throw new RuntimeException('That archive held no files that could be stored.');
            }

            return $extracted;
        } finally {
            $zip->close();
        }
    }
}
