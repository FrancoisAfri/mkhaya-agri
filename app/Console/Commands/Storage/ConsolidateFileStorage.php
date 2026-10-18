<?php

namespace App\Console\Commands\Storage;

use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moves file bytes out of per-module columns and onto the shared storage disk.
 *
 * Every module invented its own place to keep files — `data`, `file_data`, `pdf_data`, base64 in a
 * text column, one table at a time. That is fifty code paths that each have to get encryption,
 * malware scanning, streaming and safe download headers right on their own, and today forty-five of
 * them do not even set nosniff.
 *
 * The destination is deliberately the `Storage` facade rather than a particular table. Once the
 * bytes go through the disk abstraction, changing the underlying format — base64 text to BYTEA,
 * adding encryption, streaming large files — is a change inside one adapter class instead of a
 * change across every module. Consolidating first is what makes migrating once possible.
 *
 * Nothing is destroyed here. The command copies bytes to the disk, verifies them by checksum and
 * records the path; the source column is only cleared by a separate `--prune` run, after a
 * verification pass has re-read every file. A migration that deletes in the same pass as it copies
 * has no way back if the copy was wrong.
 *
 *   php artisan storage:consolidate                    # dry run — says what it would do
 *   php artisan storage:consolidate --execute          # copy bytes, record paths
 *   php artisan storage:consolidate --verify           # re-read every migrated file
 *   php artisan storage:consolidate --prune --execute  # clear source columns, verified only
 */
class ConsolidateFileStorage extends Command
{
    use RunsPerTenant;

    protected $signature = 'storage:consolidate
        {--execute : Actually write. Without this the command only reports.}
        {--verify : Re-read every migrated file and compare checksums.}
        {--prune : Clear source blob columns for rows that verify. Requires --execute.}
        {--table= : Restrict to one table.}
        {--limit=0 : Stop after this many rows, for a cautious first pass.}';

    protected $description = 'Move file bytes from per-module columns onto the shared storage disk.';

    /**
     * What holds bytes, and where they should end up.
     *
     * Deliberately not every column that looks like a blob. Signatures belong to the record that
     * captured them, `content` columns hold prose rather than files, and a payslip PDF is generated
     * from data we still have — moving those would be churn without benefit. This is the list of
     * things that are genuinely uploaded documents.
     *
     * @var list<array{table:string, blob:string, path:string, name:?string, mime:?string, folder:string}>
     */
    private const SOURCES = [
        ['table' => 'tender_documents',         'blob' => 'data',      'path' => 'storage_path', 'name' => 'original_name', 'mime' => 'mime',      'folder' => 'tenders'],
        ['table' => 'tender_library_documents', 'blob' => 'data',      'path' => 'storage_path', 'name' => 'original_name', 'mime' => 'mime',      'folder' => 'tender-library'],
        ['table' => 'tender_company_documents', 'blob' => 'data',      'path' => 'storage_path', 'name' => 'original_name', 'mime' => 'mime',      'folder' => 'tender-company'],
        ['table' => 'governance_documents',     'blob' => 'file_data', 'path' => 'file_path',    'name' => 'file_name',     'mime' => 'mime_type', 'folder' => 'governance'],
        ['table' => 'governance_board_packs',   'blob' => 'pdf_data',  'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'governance-packs'],
        ['table' => 'supplier_documents',       'blob' => 'file_data', 'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'suppliers'],
        ['table' => 'supplier_invoices',        'blob' => 'file_data', 'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'supplier-invoices'],
        ['table' => 'onboarding_documents',     'blob' => 'file_data', 'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'onboarding'],
        ['table' => 'lms_poe_evidence',         'blob' => 'file_data', 'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'lms-poe'],
        ['table' => 'hd_attachments',           'blob' => 'data',      'path' => 'storage_path', 'name' => null,            'mime' => 'mime',      'folder' => 'helpdesk'],
        ['table' => 'survey_documents',         'blob' => 'file_data', 'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'surveys'],
        ['table' => 'sheq_files',               'blob' => 'data',      'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'sheq'],
        ['table' => 'pm_document_blobs',        'blob' => 'data',      'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'projects'],
        ['table' => 'pm_contractor_invoices',   'blob' => 'file_data', 'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'project-invoices'],
        ['table' => 'procurement_requisition_attachments', 'blob' => 'file_data', 'path' => 'storage_path', 'name' => null, 'mime' => null,        'folder' => 'procurement'],
        ['table' => 'purchase_request_attachments',        'blob' => 'file_data', 'path' => 'storage_path', 'name' => null, 'mime' => null,        'folder' => 'purchase-requests'],
        ['table' => 'governance_action_evidence',          'blob' => 'file_data', 'path' => 'storage_path', 'name' => null, 'mime' => null,        'folder' => 'governance-evidence'],
        ['table' => 'fleet_job_card_images',    'blob' => 'file_data', 'path' => 'storage_path', 'name' => null,            'mime' => null,        'folder' => 'fleet'],
        ['table' => 'content_assets',           'blob' => 'data',      'path' => 'storage_path', 'name' => 'name',          'mime' => 'mime',      'folder' => 'content-assets'],

        /*
         * Found by `storage:readiness` rather than by reading code — these hold real bytes and were
         * missing from this list, so nothing had ever copied them to the disk. The lesson is in the
         * column names: a search for `data` and `file_data` finds most file columns and quietly
         * misses the ones named after what they hold.
         *
         * A contract row appears twice because it holds two distinct files, and each needs its own
         * path: the issued document and the executed copy with signatures burned into the page.
         */
        ['table' => 'conference_files',        'blob' => 'data',                 'path' => 'storage_path',        'name' => 'original_name', 'mime' => 'mime',      'folder' => 'conferences'],
        ['table' => 'payslips',                'blob' => 'pdf_data',             'path' => 'storage_path',        'name' => null,            'mime' => null,        'folder' => 'payroll/payslips'],
        ['table' => 'bureau_payslips',         'blob' => 'pdf_data',             'path' => 'storage_path',        'name' => null,            'mime' => null,        'folder' => 'payroll/bureau-payslips'],
        ['table' => 'pm_contracts',            'blob' => 'document_data',        'path' => 'document_path',       'name' => 'document_name', 'mime' => null,        'folder' => 'project-management/contracts'],
        ['table' => 'pm_contracts',            'blob' => 'signed_document_data', 'path' => 'signed_document_path','name' => 'document_name', 'mime' => null,        'folder' => 'project-management/contracts'],
        ['table' => 'contracts',               'blob' => 'file_data',            'path' => 'storage_path',        'name' => 'file_name',     'mime' => 'file_mime', 'folder' => 'contracts'],
        ['table' => 'onboarding_documents',    'blob' => 'file_data',            'path' => 'storage_path',        'name' => 'file_name',     'mime' => 'file_mime', 'folder' => 'onboarding'],
        ['table' => 'pa_kpi_evidence',         'blob' => 'data',                 'path' => 'storage_path',        'name' => 'file_name',     'mime' => 'mime',      'folder' => 'performance-appraisal/evidence'],
        ['table' => 'claim_attachments',       'blob' => 'data',                 'path' => 'storage_path',        'name' => 'original_name', 'mime' => 'mime',      'folder' => 'claims'],
        ['table' => 'survey_documents',        'blob' => 'file_data',            'path' => 'storage_path',        'name' => 'original_name', 'mime' => 'mime_type', 'folder' => 'surveys'],
        ['table' => 'lms_poe_evidence',        'blob' => 'file_data',            'path' => 'storage_path',        'name' => 'file_name',     'mime' => 'mime_type', 'folder' => 'lms/poe'],
        ['table' => 'hd_attachments',          'blob' => 'data',                 'path' => 'storage_path',        'name' => 'original_name', 'mime' => 'mime',      'folder' => 'helpdesk'],
        ['table' => 'fleet_job_card_images',   'blob' => 'file_data',            'path' => 'storage_path',        'name' => 'file_name',     'mime' => 'mime_type', 'folder' => 'fleet/job-cards'],
        ['table' => 'fleet_job_card_documents','blob' => 'file_data',            'path' => 'storage_path',        'name' => 'file_name',     'mime' => 'mime_type', 'folder' => 'fleet/job-cards'],
    ];

    private const DISK = 'documents';

    public function handle(TenantContext $tenant): int
    {
        $execute = (bool) $this->option('execute');
        $prune = (bool) $this->option('prune');

        if ($prune && ! $execute) {
            $this->error('--prune changes data. Add --execute when you mean it.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->warn('Dry run. Nothing is written. Add --execute to move bytes.');
        }

        $totals = ['scanned' => 0, 'moved' => 0, 'skipped' => 0, 'verified' => 0, 'pruned' => 0, 'failed' => 0, 'bytes' => 0];

        // Per tenant, so row-level security is in force and one company's files can never be
        // written into another's path.
        $this->eachTenant($tenant, function () use (&$totals, $execute, $prune) {
            foreach ($this->sources() as $source) {
                $this->processTable($source, $totals, $execute, $prune);
            }
        });

        $this->newLine();
        $this->table(
            ['Scanned', 'Moved', 'Verified', 'Pruned', 'Skipped', 'Failed', 'MB'],
            [[$totals['scanned'], $totals['moved'], $totals['verified'], $totals['pruned'],
              $totals['skipped'], $totals['failed'], round($totals['bytes'] / 1048576, 2)]],
        );

        if ($totals['failed'] > 0) {
            $this->error($totals['failed'] . ' row(s) failed. Nothing was pruned for those.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<array> */
    private function sources(): array
    {
        $only = $this->option('table');

        return array_values(array_filter(
            self::SOURCES,
            fn ($s) => ($only === null || $s['table'] === $only)
                && Schema::hasTable($s['table'])
                && Schema::hasColumn($s['table'], $s['blob']),
        ));
    }

    private function processTable(array $source, array &$totals, bool $execute, bool $prune): void
    {
        $table = $source['table'];
        $blob = $source['blob'];
        $pathColumn = $source['path'];

        if (! Schema::hasColumn($table, $pathColumn)) {
            // Phase 1 is a separate migration on purpose: adding columns inside a data migration
            // mixes two kinds of change and makes the whole thing harder to reason about.
            $this->line("  <fg=yellow>{$table}</> — no '{$pathColumn}' column yet, skipped. Run the pointer-column migration first.");

            return;
        }

        $limit = (int) $this->option('limit');

        $query = DB::table($table)->whereNotNull($blob)->where($blob, '!=', '');

        if ($prune) {
            // Only rows that already carry a path, so pruning can never outrun the copy.
            $query->whereNotNull($pathColumn);
        }

        $count = (clone $query)->count();
        if ($count === 0) {
            return;
        }

        $this->line("  <fg=cyan>{$table}</> — {$count} row(s) with bytes in {$blob}");

        // Chunked by id so a 27 MB file is never held alongside the next one. Memory here is the
        // real constraint: base64 in a text column costs roughly 2.3x the file size to handle.
        (clone $query)->orderBy('id')->chunkById($limit > 0 ? min($limit, 25) : 25,
            function ($rows) use ($table, $blob, $pathColumn, $source, &$totals, $execute, $prune, $limit) {
                foreach ($rows as $row) {
                    if ($limit > 0 && $totals['scanned'] >= $limit) {
                        return false;
                    }

                    $totals['scanned']++;
                    $this->processRow($table, $blob, $pathColumn, $source, $row, $totals, $execute, $prune);
                }

                return true;
            });
    }

    private function processRow(string $table, string $blob, string $pathColumn, array $source, object $row, array &$totals, bool $execute, bool $prune): void
    {
        $existingPath = $row->{$pathColumn} ?? null;

        // ── Verify / prune an already-migrated row ───────────────────────────
        if (! blank($existingPath)) {
            if (! $this->option('verify') && ! $prune) {
                $totals['skipped']++;

                return;
            }

            $ok = $this->verifyRow($existingPath, (string) $row->{$blob});

            if (! $ok) {
                $totals['failed']++;
                $this->line("    <fg=red>✗</> {$table}#{$row->id} — stored copy does not match the source. Left alone.");

                return;
            }

            $totals['verified']++;

            if ($prune && $execute) {
                DB::table($table)->where('id', $row->id)->update([$blob => null]);
                $totals['pruned']++;
            }

            return;
        }

        // ── Move a row that has not been migrated ────────────────────────────
        $raw = (string) $row->{$blob};
        $bytes = $this->decode($raw);

        if ($bytes === null || $bytes === '') {
            $totals['skipped']++;

            return;
        }

        $path = $this->pathFor($source, $row);
        $totals['bytes'] += strlen($bytes);

        if (! $execute) {
            $this->line("    would move {$table}#{$row->id} → {$path} (" . $this->readable(strlen($bytes)) . ')');
            $totals['moved']++;

            return;
        }

        try {
            Storage::disk(self::DISK)->put($path, $bytes);

            // Read it back before recording the pointer. A path written into the row before the
            // bytes are known to be readable is a row that points at nothing.
            if (! $this->verifyRow($path, $raw)) {
                throw new \RuntimeException('written bytes did not read back identically');
            }

            DB::table($table)->where('id', $row->id)->update([$pathColumn => $path]);
            $totals['moved']++;
        } catch (\Throwable $e) {
            $totals['failed']++;
            $this->line("    <fg=red>✗</> {$table}#{$row->id} — " . Str::limit($e->getMessage(), 120));
        }
    }

    /** Compares what is on the disk against what is still in the column. */
    private function verifyRow(string $path, string $sourceRaw): bool
    {
        try {
            $disk = Storage::disk(self::DISK);
            if (! $disk->exists($path)) {
                return false;
            }

            $source = $this->decode($sourceRaw);

            // A source already pruned has nothing to compare against; the file existing is all the
            // assurance available, and that is the state we were aiming for anyway.
            return $source === null || $source === ''
                ? true
                : hash('sha256', $disk->get($path)) === hash('sha256', $source);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Bytes out of a column that may hold base64 or may hold raw binary.
     *
     * Both shapes exist across these tables, and guessing wrong writes a corrupted file that looks
     * fine until somebody opens it. Strict decoding tells the two apart.
     */
    private function decode(string $value): ?string
    {
        $decoded = base64_decode($value, true);

        if ($decoded !== false && base64_encode($decoded) === preg_replace('/\s+/', '', $value)) {
            return $decoded;
        }

        return $value;
    }

    /**
     * Where a file lands.
     *
     * Built from values we control, never from a stored filename — the same rule the document
     * module follows, for the same reason.
     */
    private function pathFor(array $source, object $row): string
    {
        $uuid = $row->uuid ?? null;
        $key = is_string($uuid) && $uuid !== '' ? $uuid : ($source['table'] . '-' . $row->id);

        /*
         * The blob column belongs in the name, because a row can hold more than one file.
         *
         * A contract keeps the issued document and the executed copy in separate columns, and
         * keying the path on the row alone gave both the same filename — the second migration
         * would have overwritten the first, leaving both pointers aimed at the signed copy and the
         * issued wording gone. Caught by a dry run, which is the entire reason this command does
         * not write by default.
         */
        $key .= '-' . Str::of($source['blob'])->replaceLast('_data', '')->replace('_', '-')->value();

        $extension = 'bin';
        $name = $source['name'] ? ($row->{$source['name']} ?? null) : null;
        if (is_string($name) && str_contains($name, '.')) {
            $candidate = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
            if (preg_match('/^[a-z0-9]{1,8}$/', $candidate)) {
                $extension = $candidate;
            }
        }

        return 'migrated/' . $source['folder'] . '/' . $key . '.' . $extension;
    }

    private function readable(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return $bytes . ' B';
    }
}
