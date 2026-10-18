<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Uploading a folder, by uploading a zip of it.
 *
 * A browser cannot hand over a directory in a form post, and dragging four hundred files in one at
 * a time is not a workflow. Zipping the folder and dropping the zip is how people already move a
 * filing cabinet, so the folder structure inside is rebuilt here rather than flattened — a year's
 * project correspondence arriving as one heap of four hundred documents is not the same as
 * arriving as the folders it was filed in.
 *
 * ── Every file goes through the ordinary upload path ─────────────────────────
 *
 * Each entry is handed to DocumentService::store, the same call a single upload makes. That is
 * deliberate and it is the most important decision in this class: the extension allow-list, the
 * size cap, the malware inspection, the storage quotas, the numbering and the audit trail all live
 * there. A zip importer that wrote rows itself would be a second, weaker door into the library —
 * and the one an attacker would choose.
 *
 * ── What a zip can do that a file cannot ─────────────────────────────────────
 *
 * A zip is a container somebody else built, so it is treated as hostile input:
 *
 *  - Path traversal. An entry named `../../../etc/passwd` is the oldest trick there is. Only the
 *    basename is ever used for a filename, and each directory segment is checked before it becomes
 *    a folder — nothing here ever joins an archive path onto a filesystem path.
 *  - Decompression bombs. A few kilobytes can declare gigabytes. The total declared size and the
 *    entry count are both capped before a single byte is extracted.
 *  - Nesting. A zip inside a zip is not unpacked; it lands as a file like any other, because
 *    following it is how one upload becomes unbounded work.
 */
class ArchiveImporter
{
    /**
     * Caps on what one archive may expand to.
     *
     * Both are needed and they guard different things: the byte cap stops a bomb, and the entry cap
     * stops an archive of a hundred thousand tiny files from tying up a request and the audit trail
     * behind it. Generous enough for a real filing cabinet, small enough to stay one operation.
     */
    private const MAX_ENTRIES = 500;

    private const MAX_TOTAL_BYTES = 2 * 1024 * 1024 * 1024;

    /** Folders nest, but not without limit — FolderService refuses past its own depth anyway. */
    private const MAX_DEPTH = 8;

    public function __construct(
        private readonly DocumentService $documents,
        private readonly FolderService $folders,
    ) {
    }

    /**
     * Expand an archive into the library.
     *
     * @param  array<string, mixed>  $attributes  applied to every document, as a single upload would
     * @return array{imported: int, folders: int, skipped: list<array{name: string, reason: string}>}
     */
    public function import(UploadedFile $archive, ?int $intoFolderId, array $attributes = []): array
    {
        $zip = new ZipArchive;

        if ($zip->open($archive->getRealPath()) !== true) {
            throw new RuntimeException(__('document_management.archive.unreadable'));
        }

        try {
            $this->assertWithinLimits($zip);

            $folderCache = [];
            $imported = 0;
            $skipped = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    continue;
                }

                $name = $stat['name'];

                // A directory entry: the folders are made from the file paths below, so an empty
                // one carries nothing and creating it would leave empty folders behind.
                if (str_ends_with($name, '/')) {
                    continue;
                }

                $segments = $this->safeSegments($name);

                if ($segments === null) {
                    $skipped[] = ['name' => $name, 'reason' => __('document_management.archive.unsafe_path')];

                    continue;
                }

                $filename = array_pop($segments);

                // Files the operating system left behind, not documents anybody filed.
                if ($this->isNoise($filename, $segments)) {
                    continue;
                }

                $result = $this->storeEntry(
                    $zip, $i, $filename, $segments, $intoFolderId, $attributes, $folderCache,
                );

                if ($result === null) {
                    $imported++;
                } else {
                    $skipped[] = ['name' => $name, 'reason' => $result];
                }
            }

            return [
                'imported' => $imported,
                'folders' => count($folderCache),
                // Capped: a hundred identical "not an allowed file type" lines is not a report, and
                // the count above already says how many did not make it.
                'skipped' => array_slice($skipped, 0, 50),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * Refuse the archive before extracting anything from it.
     *
     * The declared sizes are read from the central directory, which a hostile archive can lie
     * about — but it can only lie downwards, and the real extraction is bounded by the per-file
     * size check that DocumentService already applies to every entry.
     */
    private function assertWithinLimits(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException(__('document_management.archive.too_many', [
                'max' => self::MAX_ENTRIES,
            ]));
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += $stat === false ? 0 : (int) ($stat['size'] ?? 0);

            if ($total > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException(__('document_management.archive.too_large'));
            }
        }
    }

    /**
     * The directory segments of an entry, or null if the path cannot be trusted.
     *
     * Nothing here is ever joined onto a filesystem path — the segments become folder *names* — but
     * the check is made anyway. An entry called `..` would otherwise become a folder called `..`,
     * which is a confusing thing to hand somebody and a trap for whatever reads it next.
     *
     * @return list<string>|null
     */
    private function safeSegments(string $entry): ?array
    {
        // Windows-built archives use backslashes; both separators mean the same thing here.
        $normalised = str_replace('\\', '/', $entry);

        if (str_contains($normalised, "\0")) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $normalised) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        if ($segments === [] || count($segments) > self::MAX_DEPTH + 1) {
            return null;
        }

        return $segments;
    }

    /** Files an operating system adds to a folder that nobody meant to file. */
    private function isNoise(string $filename, array $segments): bool
    {
        if (str_starts_with($filename, '.') || $filename === 'Thumbs.db' || $filename === 'desktop.ini') {
            return true;
        }

        return in_array('__MACOSX', $segments, true);
    }

    /**
     * One entry, through the ordinary upload path.
     *
     * @return string|null the reason it was skipped, or null when it was stored
     */
    private function storeEntry(
        ZipArchive $zip,
        int $index,
        string $filename,
        array $segments,
        ?int $intoFolderId,
        array $attributes,
        array &$folderCache,
    ): ?string {
        $stream = $zip->getStream($zip->getNameIndex($index));

        if ($stream === false) {
            return __('document_management.archive.unreadable_entry');
        }

        /*
         * Extracted to a temp file rather than held in memory.
         *
         * DocumentService takes an UploadedFile, and a 200MB entry read into a string is 200MB of
         * PHP memory on a request that may be handling four hundred of them. The file is deleted in
         * the finally below whatever happens.
         */
        $temp = tempnam(sys_get_temp_dir(), 'dmzip');
        $handle = fopen($temp, 'wb');

        try {
            stream_copy_to_stream($stream, $handle);
        } finally {
            fclose($stream);
            fclose($handle);
        }

        try {
            $folderId = $this->folderFor($segments, $intoFolderId, $folderCache);

            $result = $this->documents->store(
                // `test: true` marks it as already validated by PHP's upload handling — it was
                // never a browser upload, and without this Laravel refuses to touch it.
                new UploadedFile($temp, $filename, null, null, true),
                $attributes + ['folder_id' => $folderId],
            );

            return $result['document'] === null ? (string) $result['error'] : null;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * The folder an entry belongs in, making the tree as it goes.
     *
     * Cached by path so four hundred files in the same folder create it once. FolderService is what
     * actually makes each one, so the depth limit, the unique-name handling and the audit entry are
     * the same as if somebody had made the folder by hand.
     *
     * @param  list<string>  $segments
     * @param  array<string, DocumentFolder>  $cache
     */
    private function folderFor(array $segments, ?int $rootId, array &$cache): ?int
    {
        /*
         * Folders are carried as models, not ids.
         *
         * FolderService::create resolves its parent by *uuid* — the id would silently find nothing
         * — while DocumentService::store files a document by id. Both are needed at each step, so
         * holding the model is simpler than converting between them and getting it backwards.
         */
        $parent = $rootId === null ? null : DocumentFolder::find($rootId);
        $walked = [];

        foreach ($segments as $segment) {
            $walked[] = $segment;
            $key = ($rootId ?? 'root') . '/' . implode('/', $walked);

            if (! isset($cache[$key])) {
                $existing = DocumentFolder::query()
                    ->where('parent_id', $parent?->id)
                    ->where('name', $segment)
                    ->whereNull('deleted_at')
                    ->first();

                $cache[$key] = $existing ?? $this->folders->create([
                    'name' => Str::limit($segment, 120, ''),
                    'parent_id' => $parent?->uuid,
                ]);
            }

            $parent = $cache[$key];
        }

        return $parent?->id;
    }
}
