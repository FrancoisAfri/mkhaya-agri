<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmImport;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CSV import with field mapping and duplicate handling (spec section 12).
 *
 * Works for leads, contacts, accounts and deals. The same engine backs imports
 * from Bitrix24 / HubSpot / Salesforce CSV exports — the caller just supplies a
 * header→field mapping.
 */
class CrmImportService
{
    /** Importable fields per entity, surfaced to the mapping UI. */
    public const FIELDS = [
        'lead'    => ['first_name', 'last_name', 'company', 'email', 'phone', 'source', 'status', 'notes', 'product'],
        'contact' => ['first_name', 'last_name', 'company', 'email', 'phone', 'mobile', 'job_title', 'notes'],
        'account' => ['name', 'industry', 'website', 'email', 'phone', 'city', 'country', 'notes', 'product'],
        'deal'    => ['title', 'amount', 'currency', 'source', 'expected_close_date', 'product'],
    ];

    /**
     * Fields used to detect duplicates per entity.
     *
     * A lead is a person, so it is matched on the person. `company` used to be in this list and the
     * keys are checked with OR, which meant the second person at an employer already on file was
     * discarded as a duplicate of a colleague — one real import lost 590 of 716 rows that way, and
     * kept exactly one lead per company. An employer with forty staff is not forty duplicates.
     *
     * Companies belong to the account entity, where `name` is the right key precisely because an
     * account IS the company.
     */
    private const DEDUP_KEYS = [
        'lead'    => ['email'],
        'contact' => ['email'],
        'account' => ['name'],
        'deal'    => ['title'],
    ];

    /**
     * Read headers + a few sample rows for the mapping screen.
     *
     * @return array{headers:array, sample:array, suggested:array}
     */
    public function preview(string $path, string $entityType, int $sampleSize = 5): array
    {
        [$headers, $rows] = $this->readCsv($path, $sampleSize);

        // Suggest a mapping by matching headers to field names loosely.
        $fields    = self::FIELDS[$entityType] ?? [];
        $suggested = [];
        foreach ($headers as $h) {
            $norm = Str::of($h)->lower()->replace([' ', '-'], '_')->__toString();
            $suggested[$h] = in_array($norm, $fields, true) ? $norm : '';
        }

        return ['headers' => $headers, 'sample' => $rows, 'suggested' => $suggested];
    }

    /**
     * Run the import.
     *
     * @param  array  $mapping  [csv_header => crm_field]
     * @param  string $dedup    skip|update|create
     */
    /**
     * What this file would do, without doing it.
     *
     * Deliberately the real import rolled back, rather than a second implementation of the same
     * rules. Every alternative — counting duplicates with a separate query, re-deriving the
     * required-field check — is a copy that drifts from the importer the first time either side
     * changes, and a preview that promises 590 and delivers 126 is worse than no preview.
     *
     * It therefore accounts for the things a naive count cannot: a row that duplicates an earlier
     * row *in the same file* (the real import sees it because the earlier one is already written),
     * an account created on the fly for a contact's company, and the batch product default.
     *
     * @return array{total:int, create:int, update:int, skip:int, duplicates:int, rows:array}
     */
    public function dryRun(string $path, string $entityType, array $mapping, string $dedup, ?int $defaultProductId = null): array
    {
        DB::beginTransaction();

        try {
            $import = $this->import($path, $entityType, $mapping, $dedup, 'dry-run.csv', $defaultProductId);

            return [
                'total'      => $import->total_rows,
                'create'     => $import->created_count,
                'update'     => $import->updated_count,
                'skip'       => $import->skipped_count,
                'duplicates' => $import->duplicate_count,
                'rows'       => $import->errors ?: [],
            ];
        } finally {
            // Always. A dry run that committed because something threw halfway is the one outcome
            // worse than not having a dry run at all.
            DB::rollBack();
        }
    }

    public function import(string $path, string $entityType, array $mapping, string $dedup, string $fileName, ?int $defaultProductId = null): CrmImport
    {
        [$headers, $rows] = $this->readCsv($path, PHP_INT_MAX);

        // Required fields no longer block the whole import — rows missing a
        // required value are skipped and reported so good rows still come in.
        $required = $this->requiredFields($entityType);

        // Create the import row first so created records can be stamped with its
        // id — that's what makes the upload reversible.
        $import = CrmImport::create([
            'uuid'           => Str::uuid(),
            'entity_type'    => $entityType,
            'file_name'      => $fileName,
            'source'         => 'csv',
            'mapping'        => $mapping,
            'dedup_strategy' => $dedup,
            'total_rows'     => count($rows),
            'status'         => 'processing',
            'created_by'     => Auth::id(),
        ]);

        $created = $updated = $skipped = $duplicates = 0;

        // Every row that does not land, and why. Counting them was never enough: "590 skipped" tells
        // somebody their import went wrong without telling them which rows or what to do about it,
        // so the only way to find out was to compare 716 spreadsheet lines against the CRM by hand.
        $issues = [];
        $pipeline = $entityType === 'deal'
            ? (CrmPipeline::where('is_default', true)->with('stages')->first() ?? CrmPipeline::with('stages')->first())
            : null;

        foreach ($rows as $i => $row) {
            $attrs = $this->mapRow($headers, $row, $mapping);
            $this->resolveProductColumn($attrs, $entityType);
            if (empty(array_filter($attrs, fn ($v) => $v !== '' && $v !== null))) {
                $issues[] = $this->issue($i, $attrs, 'Row is empty, or none of its columns are mapped');
                $skipped++;
                continue;
            }

            // Per-row required-field check: skip & report this row, keep going.
            $missing = [];
            foreach ($required as $field) {
                if (! isset($attrs[$field]) || trim((string) $attrs[$field]) === '') {
                    $missing[] = $field;
                }
            }
            if ($missing) {
                $issues[] = $this->issue($i, $attrs, 'Missing required: ' . implode(', ', $missing));
                $skipped++;
                continue;
            }

            // Apply the batch-level product/service to a real row that didn't map its own.
            if ($defaultProductId && empty($attrs['product_id']) && in_array($entityType, ['lead', 'account', 'deal'], true)) {
                $attrs['product_id'] = $defaultProductId;
            }

            try {
                // A contact's company is its linked account: resolve the company
                // name to an existing account (or create one) and link by id.
                if ($entityType === 'contact') {
                    $this->resolveCompanyToAccount($attrs, $import->id);
                }

                [$existing, $matchedOn] = $this->findDuplicate($entityType, $attrs);

                if ($existing) {
                    $duplicates++;
                    if ($dedup === 'skip') {
                        // Named against the record it matched, so somebody can see at a glance
                        // whether it is a genuine re-import or a matching rule that is too broad.
                        $issues[] = $this->issue($i, $attrs, sprintf(
                            'Already on file — matched an existing %s on %s',
                            $entityType,
                            $matchedOn ?? 'a duplicate check',
                        ));
                        $skipped++;
                        continue;
                    }
                    if ($dedup === 'update') { $existing->fill($attrs)->save(); $updated++; continue; }
                    // dedup === 'create' falls through to create a new record.
                }

                $this->createRecord($entityType, $attrs, $pipeline, $import->id);
                $created++;
            } catch (\Throwable $e) {
                $issues[] = $this->issue($i, $attrs, $e->getMessage());
                $skipped++;
            }
        }

        $import->update([
            'created_count'   => $created,
            'updated_count'   => $updated,
            'skipped_count'   => $skipped,
            'duplicate_count' => $duplicates,
            // All of them, not the first fifty. A 700-row file that skips 590 is exactly the case
            // where the truncated list is useless, and this is a few hundred KB of JSON at worst.
            'errors'          => $issues,
            'status'          => 'completed',
        ]);

        return $import;
    }

    /**
     * One skipped row, described well enough to find it again.
     *
     * Carries the line number as the client sees it in their spreadsheet — the header is line 1 —
     * along with whatever identifies the person, because "row 417" sends somebody counting and
     * "row 417, Thabo Mokoena, thabo@acme.co.za" does not.
     *
     * @return array{row:int, reason:string, name:string, email:string, company:string}
     */
    private function issue(int $index, array $attrs, string $reason): array
    {
        $name = trim(($attrs['first_name'] ?? '') . ' ' . ($attrs['last_name'] ?? ''));

        return [
            'row'     => $index + 2,   // +2: the header is line 1 and $index is 0-based
            'reason'  => $reason,
            'name'    => $name !== '' ? $name : (string) ($attrs['name'] ?? $attrs['title'] ?? ''),
            'email'   => (string) ($attrs['email'] ?? ''),
            'company' => (string) ($attrs['company'] ?? ''),
        ];
    }

    /**
     * Required fields configured per company (CRM Settings → Import rules).
     * Returns only fields that are actually importable for the entity.
     *
     * @return string[]
     */
    public function requiredFields(string $entityType): array
    {
        $raw = CrmSetting::get('import_required_fields');
        if (! $raw) {
            return [];
        }

        $all    = json_decode($raw, true) ?: [];
        $fields = $all[$entityType] ?? [];

        return array_values(array_intersect($fields, self::FIELDS[$entityType] ?? []));
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /**
     * The bytes behind a stored upload, wherever the disk actually keeps them.
     *
     * The `local` disk on this platform is a *database* driver, so it has no filesystem root and
     * `Storage::path()` hands back a relative string that resolves to nothing. Reading that with
     * file_get_contents() failed silently, readCsv() returned no headers, and the import screen
     * drew an empty column-mapping table with no error — the file looked accepted and the mapping
     * step looked broken.
     *
     * So the disk is asked first, and a real filesystem path is still honoured for callers that
     * hand one over (a temp file, a CLI run).
     */
    private function contentsOf(string $path): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $path), '/');

        try {
            if (Storage::exists($relative)) {
                return Storage::get($relative);
            }
        } catch (\Throwable) {
            // Disk not readable in this context — fall through to the filesystem.
        }

        if (is_file($path) && is_readable($path)) {
            return @file_get_contents($path) ?: null;
        }

        return null;
    }

    private function readCsv(string $path, int $limit): array
    {
        $raw = $this->contentsOf($path);
        if ($raw === null || $raw === '') return [[], []];

        // Strip UTF-8 BOM (Excel adds one) and normalise line endings so classic
        // Mac (\r) and Windows (\r\n) exports parse the same as Unix (\n).
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        $lines = array_values(array_filter(
            explode("\n", $raw),
            fn ($l) => trim($l) !== ''
        ));
        if (empty($lines)) return [[], []];

        // Detect the delimiter from the header (comma / semicolon / tab / pipe).
        $delimiter = $this->detectDelimiter($lines[0]);

        $headers = array_map(fn ($h) => trim((string) $h), str_getcsv($lines[0], $delimiter, '"', ""));

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            if (count($rows) >= $limit) break;
            $data = str_getcsv($line, $delimiter, '"', "");
            // Skip fully empty rows.
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) continue;
            $rows[] = $data;
        }

        return [$headers, $rows];
    }

    /** Guess the column delimiter by counting candidates in the header line. */
    private function detectDelimiter(string $headerLine): string
    {
        $counts = [
            ','  => substr_count($headerLine, ','),
            ';'  => substr_count($headerLine, ';'),
            "\t" => substr_count($headerLine, "\t"),
            '|'  => substr_count($headerLine, '|'),
        ];
        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }

    private function mapRow(array $headers, array $row, array $mapping): array
    {
        $attrs = [];
        foreach ($headers as $idx => $header) {
            $field = $mapping[$header] ?? '';
            if ($field === '' || ! isset($row[$idx])) continue;
            $attrs[$field] = trim((string) $row[$idx]);
        }
        return $attrs;
    }

    /**
     * Convert a contact's free-text "company" into an account link. Matches an
     * existing account by name (case-insensitive) or creates a new one, then
     * sets account_id and drops the non-column "company" key.
     */
    private function resolveCompanyToAccount(array &$attrs, ?int $importId = null): void
    {
        $company = trim((string) ($attrs['company'] ?? ''));
        unset($attrs['company']);
        if ($company === '') return;

        $account = CrmAccount::whereRaw('LOWER(name) = ?', [mb_strtolower($company)])->first()
            ?? CrmAccount::create([
                'uuid'          => Str::uuid(),
                'name'          => $company,
                'owner_id'      => Auth::id(),
                'created_by'    => Auth::id(),
                'crm_import_id' => $importId, // so reversing the import also removes accounts it created
            ]);

        $attrs['account_id'] = $account->id;
    }

    /**
     * The record this row would duplicate, and which field said so.
     *
     * The matching field is returned as well as the record because "590 skipped" is not something
     * anybody can act on — the report has to be able to say *what* it matched, so somebody can tell
     * a genuine re-import from a rule that is too broad.
     *
     * @return array{0: mixed, 1: ?string} the existing record and the field that matched
     */
    private function findDuplicate(string $entityType, array $attrs): array
    {
        $keys = self::DEDUP_KEYS[$entityType] ?? [];
        $model = $this->modelClass($entityType);

        foreach ($keys as $key) {
            if (! empty($attrs[$key])) {
                $hit = $model::where($key, $attrs[$key])->first();
                if ($hit) {
                    return [$hit, $key];
                }
            }
        }

        // A person with no email address still should not be imported twice. Matched on the whole
        // name together with the employer rather than on any one of them, so two different people
        // called Thabo at the same company are still two people.
        if ($entityType === 'lead' && empty($attrs['email'])
            && ! empty($attrs['first_name']) && ! empty($attrs['last_name'])) {
            $hit = $model::where('first_name', $attrs['first_name'])
                ->where('last_name', $attrs['last_name'])
                ->when(! empty($attrs['company']), fn ($q) => $q->where('company', $attrs['company']))
                ->first();

            if ($hit) {
                return [$hit, 'name and company'];
            }
        }

        return [null, null];
    }

    /**
     * Convert a mapped "product" column (a product/service name or code) into a
     * product_id on the record. Unknown values are left unlinked (null).
     */
    private function resolveProductColumn(array &$attrs, string $entityType): void
    {
        if (! array_key_exists('product', $attrs)) {
            return;
        }
        $needle = trim((string) $attrs['product']);
        unset($attrs['product']);

        if ($needle !== '' && in_array($entityType, ['lead', 'account', 'deal'], true)) {
            $attrs['product_id'] = \App\Modules\CRM\Models\CrmProduct::query()
                ->where('name', 'ilike', $needle)
                ->orWhere('code', 'ilike', $needle)
                ->value('id');
        }
    }

    private function createRecord(string $entityType, array $attrs, ?CrmPipeline $pipeline, ?int $importId = null)
    {
        $attrs['uuid']          = Str::uuid();
        $attrs['owner_id']      = $attrs['owner_id'] ?? Auth::id();
        $attrs['created_by']    = Auth::id();
        $attrs['crm_import_id'] = $importId;

        if ($entityType === 'lead' && empty($attrs['status'])) {
            $attrs['status'] = 'new';
        }

        if ($entityType === 'deal') {
            $stage = $pipeline?->stages->first();
            if (! $pipeline || ! $stage) {
                throw new \RuntimeException('No pipeline configured for deal import.');
            }
            $attrs['pipeline_id']      = $pipeline->id;
            $attrs['stage_id']         = $stage->id;
            $attrs['status']           = 'open';
            $attrs['probability']      = $stage->probability;
            $attrs['currency']         = $attrs['currency'] ?? 'ZAR';
            $attrs['stage_changed_at'] = now();
            $attrs['amount']           = is_numeric($attrs['amount'] ?? null) ? $attrs['amount'] : 0;
        }

        return $this->modelClass($entityType)::create($attrs);
    }

    private function modelClass(string $entityType): string
    {
        return match ($entityType) {
            'lead'    => CrmLead::class,
            'contact' => CrmContact::class,
            'account' => CrmAccount::class,
            'deal'    => CrmDeal::class,
            default   => throw new \InvalidArgumentException("Unknown entity: {$entityType}"),
        };
    }
}
