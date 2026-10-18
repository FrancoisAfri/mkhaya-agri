<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Looking inside a zip without taking the whole thing.
 *
 * A reseller pack arrives as one archive of forty images and somebody wants the third one. Without
 * this the only route is to download the lot, unpack it somewhere, find the file, and the document
 * system has no idea any of that happened — so the audit trail says a zip was downloaded and
 * nothing about what was actually wanted.
 *
 * ── Why the bytes go to a temp file ──────────────────────────────────────────
 *
 * ZipArchive reads a path, and these files live in Postgres rather than on a disk. The bytes are
 * written to the system temp directory, read, and removed in a finally — the same shape
 * TextExtractor already uses for Office files, for the same reason.
 *
 * ── What an archive is allowed to do ─────────────────────────────────────────
 *
 * A zip is a file format that describes how much data to produce, which is not the same as how
 * much data it contains. A few kilobytes can declare petabytes, and a reader that believes the
 * declaration exhausts the machine. So the listing is capped, a single entry is capped, and an
 * entry whose claimed size is wildly out of proportion to its compressed size is refused rather
 * than expanded. Listing is the safe half — it reads the directory, not the contents — and the
 * caps there are about keeping a payload sane rather than about safety.
 */
class ArchiveBrowser
{
    /** Archive formats worth opening. Anything else is simply not an archive to this module. */
    private const SUPPORTED = ['zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub', 'jar'];

    /** Browsing is for archives somebody uploaded as an archive, not for Office files. */
    private const BROWSABLE = ['zip', 'epub', 'jar'];

    /** A listing is a page, not a filesystem dump. */
    private const MAX_ENTRIES = 2000;

    /** The largest single file this will hand back from inside an archive. */
    private const MAX_ENTRY_BYTES = 26_214_400; // 25 MB

    /**
     * Refuse to expand an entry that claims to be more than this many times its compressed size.
     *
     * The classic zip bomb is a few kilobytes that unpacks to terabytes, and the ratio is the only
     * thing that distinguishes it from an ordinary well-compressed file before you expand it.
     * Text compresses perhaps twenty to one; a thousand to one is not a document.
     */
    private const MAX_RATIO = 1000;

    public function __construct(private readonly DocumentService $documents) {}

    public function isBrowsable(?string $extension): bool
    {
        return $extension !== null && in_array(Str::lower($extension), self::BROWSABLE, true);
    }

    /**
     * What is inside, without expanding any of it.
     *
     * @return array{supported: bool, reason: ?string, entries: list<array<string,mixed>>, truncated: bool, total: int}
     */
    public function listing(DocumentVersion $version): array
    {
        if (! $this->isBrowsable($version->extension)) {
            return $this->nothing('This file is not an archive.');
        }

        if (! class_exists(ZipArchive::class)) {
            return $this->nothing('Archives cannot be opened on this server.');
        }

        $bytes = $this->documents->contents($version);

        if ($bytes === null) {
            return $this->nothing('The file could not be read.');
        }

        return $this->withArchive($bytes, function (ZipArchive $zip): array {
            $entries = [];
            $total = $zip->numFiles;

            for ($i = 0; $i < min($total, self::MAX_ENTRIES); $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    continue;
                }

                $name = (string) $stat['name'];

                // Directory entries are the shape of the archive, not files in it.
                if (str_ends_with($name, '/')) {
                    continue;
                }

                // Mac and Windows both litter archives with metadata nobody asked to see.
                if (str_starts_with($name, '__MACOSX/') || str_ends_with($name, '/.DS_Store') || $name === '.DS_Store') {
                    continue;
                }

                $size = (int) $stat['size'];

                $entries[] = [
                    'index' => $i,
                    'name' => $name,
                    // Shown separately so a long path does not hide what the file is called.
                    'basename' => basename($name),
                    'folder' => trim((string) dirname($name), '.') ?: null,
                    'extension' => Str::lower((string) pathinfo($name, PATHINFO_EXTENSION)) ?: null,
                    'size' => $size,
                    'size_label' => $this->humanSize($size),
                    'compressed' => (int) $stat['comp_size'],
                    'modified' => $stat['mtime'] ? date('Y-m-d H:i', (int) $stat['mtime']) : null,
                    'previewable' => $this->looksPreviewable((string) $name),
                    'too_large' => $size > self::MAX_ENTRY_BYTES,
                ];
            }

            return [
                'supported' => true,
                'reason' => null,
                'entries' => $entries,
                'truncated' => $total > self::MAX_ENTRIES,
                'total' => $total,
            ];
        }) ?? $this->nothing('The archive could not be opened. It may be damaged or password protected.');
    }

    /**
     * One file out of the archive.
     *
     * @return array{name: string, mime: string, bytes: string}|null
     */
    public function entry(DocumentVersion $version, int $index): ?array
    {
        if (! $this->isBrowsable($version->extension)) {
            return null;
        }

        $bytes = $this->documents->contents($version);

        if ($bytes === null) {
            return null;
        }

        return $this->withArchive($bytes, function (ZipArchive $zip) use ($index): ?array {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                return null;
            }

            $size = (int) $stat['size'];
            $compressed = max(1, (int) $stat['comp_size']);

            if ($size > self::MAX_ENTRY_BYTES) {
                return null;
            }

            // A few kilobytes claiming to be gigabytes is not a document.
            if (intdiv($size, $compressed) > self::MAX_RATIO) {
                return null;
            }

            $content = $zip->getFromIndex($index, self::MAX_ENTRY_BYTES);

            if ($content === false) {
                return null;
            }

            /*
             * The basename, always.
             *
             * An entry may be named `../../something`, and while nothing here writes to disk, that
             * name would go straight into a Content-Disposition header and then into whatever the
             * browser does with it. The archive does not get to choose where the file lands.
             */
            $name = basename(str_replace('\\', '/', (string) $stat['name']));

            return [
                'name' => $name !== '' ? $name : 'file',
                'mime' => $this->mimeFor($name, $content),
                'bytes' => $content,
            ];
        });
    }

    /**
     * Run a callback against the archive, cleaning up the temp file whatever happens.
     *
     * @template T
     * @param  callable(ZipArchive): T  $callback
     * @return T|null
     */
    private function withArchive(string $bytes, callable $callback): mixed
    {
        $file = tempnam(sys_get_temp_dir(), 'dm_archive_');

        if ($file === false) {
            return null;
        }

        $zip = new ZipArchive;

        try {
            file_put_contents($file, $bytes);

            if ($zip->open($file, ZipArchive::RDONLY) !== true) {
                return null;
            }

            try {
                return $callback($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($file);
        }
    }

    /** @return array{supported: bool, reason: ?string, entries: list<array<string,mixed>>, truncated: bool, total: int} */
    private function nothing(string $reason): array
    {
        return ['supported' => false, 'reason' => $reason, 'entries' => [], 'truncated' => false, 'total' => 0];
    }

    private function looksPreviewable(string $name): bool
    {
        return in_array(
            Str::lower((string) pathinfo($name, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'pdf', 'txt', 'csv', 'md'],
            true,
        );
    }

    /**
     * The type to serve an extracted file as.
     *
     * Taken from the bytes first and the name second. A name inside an archive is whatever the
     * person who built it typed, and serving a file as a type it is not is how a browser is
     * persuaded to run something.
     */
    private function mimeFor(string $name, string $content): string
    {
        if (function_exists('finfo_open') && ($finfo = finfo_open(FILEINFO_MIME_TYPE)) !== false) {
            $detected = finfo_buffer($finfo, $content);
            finfo_close($finfo);

            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return match (Str::lower((string) pathinfo($name, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'txt', 'md' => 'text/plain',
            'csv' => 'text/csv',
            default => 'application/octet-stream',
        };
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        foreach (['KB', 'MB', 'GB'] as $i => $unit) {
            $value = $bytes / (1024 ** ($i + 1));

            if ($value < 1024 || $unit === 'GB') {
                return round($value, $value < 10 ? 1 : 0).' '.$unit;
            }
        }

        return $bytes.' B';
    }
}
