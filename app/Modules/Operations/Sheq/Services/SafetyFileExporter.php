<?php

namespace App\Modules\Operations\Sheq\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * The whole safety file as one thing you can hand over.
 *
 * Downloading twenty-five certificates one at a time is not an alternative to a lever-arch folder;
 * it is the same problem with extra steps. What a client's auditor asks for is the file, and what a
 * site manager emails a principal contractor is the file. So it bundles.
 *
 * A zip rather than one merged PDF, deliberately. The documents in a real safety file are PDFs,
 * photographs, spreadsheets and the occasional Word file, and merging that into a single PDF means
 * either dropping whatever will not merge or rasterising it. A zip carries every document exactly
 * as it was supplied — which is the point of evidence — and the index inside it is the PDF that
 * makes the bundle navigable.
 *
 * Three properties the index is there for:
 *
 * - **It states the gaps.** A bundle of what exists is a selective bundle; the index lists the
 *   sections with nothing in them, because that is what an auditor is looking for and the one
 *   thing a folder of files cannot say.
 * - **It distinguishes a record from its paper.** A document can be recorded with an expiry and no
 *   file attached. In a zip that document simply would not appear, and its absence would read as
 *   "never supplied" rather than "logged but the certificate was never uploaded".
 * - **It says when it was made, and that it goes stale.** A safety file is a snapshot of expiry
 *   dates. One printed in March says nothing true in September, and a bundle with no date on it
 *   invites exactly that mistake.
 */
class SafetyFileExporter
{
    /**
     * Caps, so one export cannot take the server down or produce something nobody can email.
     *
     * Exceeding either is reported in the index rather than silently truncating: a bundle missing
     * documents without saying so is worse than no bundle, because it looks complete.
     */
    private const MAX_FILES = 300;

    private const MAX_BYTES = 209_715_200;   // 200 MiB of actual documents

    public function __construct(
        private readonly SafetyFileService $safetyFile,
        private readonly FileService $files,
    ) {}

    /**
     * Build the bundle on disk and say where it is.
     *
     * The caller streams and deletes it. Written to a temporary file rather than held in memory
     * because a construction safety file is routinely tens of megabytes.
     *
     * @return array{path:string, name:string, documents:int, skipped:list<string>, bytes:int}
     */
    public function export(int $companyId, string $contractorUuid, ?int $actorId = null): array
    {
        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $contractorUuid)
            ->whereNull('deleted_at')
            ->first(['id', 'name', 'trade', 'registration_number', 'access_status']);

        if ($contractor === null) {
            throw new RuntimeException("No contractor {$contractorUuid} in this company.");
        }

        $file = $this->safetyFile->assemble($companyId, $contractorUuid);
        $attachments = $this->attachments($companyId, (int) $contractor->id);

        $path = tempnam(sys_get_temp_dir(), 'sheq-safety-file-');

        if ($path === false) {
            throw new RuntimeException('The export could not be started: no temporary file.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export could not be started: the archive would not open.');
        }

        $written = 0;
        $bytes = 0;
        $skipped = [];
        $placed = [];

        foreach ($file['sections'] as $index => $section) {
            // Numbered in the order the file is assembled, so the folders sort the way the
            // dividers do rather than alphabetically.
            $folder = sprintf('%02d %s', $index + 1, $this->safeName($section['name']));

            foreach ($section['items'] ?? [] as $item) {
                $attachment = $attachments[$item['uuid']] ?? null;

                if ($attachment === null) {
                    // Recorded with no certificate attached. Named in the index, because in a zip
                    // its absence would read as "never supplied".
                    $skipped[] = sprintf('%s — %s (recorded, no file attached)',
                        $section['name'], $item['title'] ?: $item['document_type']);

                    continue;
                }

                if ($written >= self::MAX_FILES || $bytes >= self::MAX_BYTES) {
                    $skipped[] = sprintf('%s — %s (left out: the bundle reached its limit)',
                        $section['name'], $attachment->original_name);

                    continue;
                }

                $content = $this->files->bytes($companyId, $attachment->file_uuid);

                if ($content === null) {
                    // One unreadable document must not cost the other twenty-nine.
                    $skipped[] = sprintf('%s — %s (stored file could not be read)',
                        $section['name'], $attachment->original_name);

                    continue;
                }

                $entry = $folder.'/'.$this->uniqueEntry($placed, $folder,
                    $this->safeName($attachment->original_name, keepExtension: true));

                $zip->addFromString($entry, $content);
                $written++;
                $bytes += strlen($content);
            }
        }

        $zip->addFromString('00 Index.pdf', Pdf::loadView('sheq.safety-file-index', [
            'contractor' => $contractor,
            'company' => DB::table('companies')->where('id', $companyId)->value('name'),
            'file' => $file,
            'attachments' => $attachments,
            'documents' => $written,
            'skipped' => $skipped,
            'generatedAt' => Carbon::now(),
            'profileName' => $this->profileName($file['profile']),
        ])->setPaper('a4')->output());

        $zip->close();

        $this->audit($companyId, $actorId, (int) $contractor->id, $written, $skipped);

        return [
            'path' => $path,
            'name' => $this->safeName($contractor->name).'-safety-file-'
                .Carbon::now()->format('Y-m-d').'.zip',
            'documents' => $written,
            'skipped' => $skipped,
            'bytes' => $bytes,
        ];
    }

    /**
     * The stored file behind each contractor document, keyed by the document's uuid.
     *
     * One query rather than one per document: a safety file has twenty-five sections and a
     * per-item lookup would be the slowest part of the export by a wide margin.
     *
     * @return array<string, object>
     */
    private function attachments(int $companyId, int $contractorId): array
    {
        return DB::table('sheq_contractor_documents as d')
            ->join('sheq_files as f', function ($join) {
                $join->on('f.subject_id', '=', 'd.id')
                    ->where('f.subject_type', '=', 'CONTRACTOR_DOCUMENT');
            })
            ->where('d.company_id', $companyId)->where('d.contractor_id', $contractorId)
            ->whereNull('d.deleted_at')->whereNull('f.deleted_at')
            ->where('f.upload_status', 'STORED')
            ->orderBy('f.id')
            ->get(['d.uuid as document_uuid', 'f.uuid as file_uuid', 'f.original_name', 'f.size',
                'f.mime'])
            ->keyBy('document_uuid')
            ->all();
    }

    private function profileName(string $profile): string
    {
        return \App\Modules\Operations\Sheq\Support\SafetyFileSections::profiles()[$profile]['name']
            ?? $profile;
    }

    /**
     * A filename a zip on any operating system can hold.
     *
     * Contractor names and scanned filenames carry slashes, colons and the occasional newline. A
     * path separator inside an entry name silently creates a directory, which is how an export
     * ends up with a folder called "Pty" inside one called "Acme (".
     */
    private function safeName(string $name, bool $keepExtension = false): string
    {
        $extension = '';

        if ($keepExtension && str_contains($name, '.')) {
            $extension = '.'.Str::lower(pathinfo($name, PATHINFO_EXTENSION));
            $name = pathinfo($name, PATHINFO_FILENAME);
        }

        $clean = preg_replace('/[^\p{L}\p{N}\-_ ]+/u', ' ', $name) ?? $name;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);

        return ($clean === '' ? 'document' : Str::limit($clean, 80, '')).$extension;
    }

    /** Two scans both called `scan0001.pdf` must not overwrite each other inside the zip. */
    private function uniqueEntry(array &$placed, string $folder, string $name): string
    {
        $key = $folder.'/'.Str::lower($name);

        if (! isset($placed[$key])) {
            $placed[$key] = 1;

            return $name;
        }

        $suffix = ++$placed[$key];
        $extension = str_contains($name, '.') ? '.'.pathinfo($name, PATHINFO_EXTENSION) : '';

        return pathinfo($name, PATHINFO_FILENAME)." ({$suffix})".$extension;
    }

    /**
     * Who took a copy of the whole file.
     *
     * A bundle is every compliance document a contractor has supplied, in one archive that leaves
     * the system. That is a different act from opening one certificate, and the question "who
     * exported this, and what was in it" is one somebody will eventually ask.
     */
    private function audit(int $companyId, ?int $actorId, int $contractorId, int $documents,
        array $skipped): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => 'safety_file.exported',
            'subject_type' => 'Contractor',
            'subject_id' => $contractorId,
            'after' => json_encode(['documents' => $documents, 'left_out' => count($skipped)]),
            'reason' => $skipped === []
                ? sprintf('Safety file exported: %d document(s).', $documents)
                : sprintf('Safety file exported: %d document(s), %d left out.',
                    $documents, count($skipped)),
        ]);
    }
}
