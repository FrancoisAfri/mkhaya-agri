<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmImport;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Services\CrmImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CSV import with column mapping + duplicate handling (spec section 12).
 *
 * Two steps: preview() stores the upload and returns headers + sample rows + a
 * suggested mapping; commit() runs the import against the stored file.
 */
class ImportController extends Controller
{
    public function __construct(private CrmImportService $service)
    {
    }

    public function index(): Response
    {
        $imports = CrmImport::with('createdBy.person')->latest()->limit(50)->get()
            ->map(fn ($im) => [
                'id'         => $im->id,
                'entity'     => $im->entity_type,
                'file'       => $im->file_name,
                'total'      => $im->total_rows,
                'created'    => $im->created_count,
                'updated'    => $im->updated_count,
                'skipped'    => $im->skipped_count,
                'duplicates' => $im->duplicate_count,
                'status'     => $im->status,
                'reversed'   => $im->reversed_at !== null,
                'by'         => $im->createdBy?->person?->first_name,
                'at'         => $im->created_at?->diffForHumans(),
                'issues'     => $im->errors ?: [],
            ]);

        // Required-field rules per entity (CRM Settings → Import rules), so the
        // mapping screen can mark them and block commit when one isn't mapped.
        $required = collect(array_keys(CrmImportService::FIELDS))
            ->mapWithKeys(fn ($e) => [$e => $this->service->requiredFields($e)])
            ->all();

        return Inertia::render('Modules/CRM/Import', [
            'imports'  => $imports,
            'fields'   => CrmImportService::FIELDS,
            'required' => $required,
            'products' => \App\Modules\CRM\Models\CrmProduct::active()->orderBy('name')->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * Reverse a corrupted import: soft-delete every record it created (incl. any
     * company accounts auto-created during a contact import). Records that were
     * *updated* by the import are not rolled back.
     */
    public function reverse(CrmImport $import): RedirectResponse
    {
        if ($import->isReversed()) {
            return back()->with('error', 'This import has already been reversed.');
        }

        $model   = $this->entityModel($import->entity_type);
        $removed = $model::where('crm_import_id', $import->id)->delete();

        if ($import->entity_type !== 'account') {
            $removed += CrmAccount::where('crm_import_id', $import->id)->delete();
        }

        $import->update(['reversed_at' => now(), 'reversed_by' => Auth::id()]);

        return back()->with('success', "Import reversed — {$removed} created record(s) removed.");
    }

    private function entityModel(string $entityType): string
    {
        return match ($entityType) {
            'lead'    => CrmLead::class,
            'contact' => CrmContact::class,
            'account' => CrmAccount::class,
            'deal'    => CrmDeal::class,
            default   => throw new \InvalidArgumentException("Unknown entity: {$entityType}"),
        };
    }

    /**
     * One illustrative sample row per entity, keyed by field name. Shows users
     * the expected format (date, amount, etc.) without affecting a real import.
     */
    private const TEMPLATE_SAMPLES = [
        'lead' => [
            'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'company' => 'Acme Logistics',
            'email' => 'thabo@acme.co.za', 'phone' => '+27 82 123 4567',
            'source' => 'Website', 'status' => 'new', 'notes' => 'Requested a quote',
        ],
        'contact' => [
            'first_name' => 'Lindiwe', 'last_name' => 'Dlamini', 'company' => 'Acme Logistics',
            'email' => 'lindiwe@example.co.za', 'phone' => '+27 11 555 0100', 'mobile' => '+27 83 987 6543',
            'job_title' => 'Procurement Manager', 'notes' => 'Prefers email contact',
        ],
        'account' => [
            'name' => 'Acme Logistics', 'industry' => 'Transport', 'website' => 'https://acme.co.za',
            'email' => 'info@acme.co.za', 'phone' => '+27 21 555 0199',
            'city' => 'Cape Town', 'country' => 'South Africa', 'notes' => 'Key account',
        ],
        'deal' => [
            'title' => 'Fleet maintenance contract', 'amount' => '150000', 'currency' => 'ZAR',
            'source' => 'Referral', 'expected_close_date' => '2026-09-30',
        ],
    ];

    /**
     * Download a CSV template for the given entity type. The header row matches
     * exactly the importable fields, followed by one filled-in example row so
     * users can see the expected format. They replace the example with real data.
     */
    public function template(string $entityType): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless(isset(CrmImportService::FIELDS[$entityType]), 404);

        $columns = CrmImportService::FIELDS[$entityType];
        $sample  = self::TEMPLATE_SAMPLES[$entityType] ?? [];
        $exampleRow = array_map(fn ($field) => $sample[$field] ?? '', $columns);
        $filename = "crm-{$entityType}-import-template.csv";

        return response()->streamDownload(function () use ($columns, $exampleRow) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 cleanly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            fputcsv($out, $exampleRow);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(Request $request): RedirectResponse
    {
        $request->validate([
            'file'        => 'required|file|max:10240',
            'entity_type' => 'required|in:lead,contact,account,deal',
        ]);

        // Validate by extension, not sniffed MIME: a .csv exported from Excel / Numbers /
        // Google Sheets is frequently sniffed as application/vnd.ms-excel or octet-stream,
        // so `mimes:csv,txt` silently rejected it and the preview never appeared.
        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (! in_array($ext, ['csv', 'txt'], true)) {
            return back()->withErrors(['file' => 'Please upload a .csv file (export as CSV from Excel, Numbers or Google Sheets).']);
        }

        $token = (string) Str::uuid();
        $path  = $request->file('file')->storeAs('crm-imports', $token . '.csv');

        // The storage-relative path, not Storage::path(): the `local` disk here is a database
        // driver with no filesystem root, so an absolute path is a fiction and reading one
        // silently produced an empty preview.
        $preview = $this->service->preview($path, $request->entity_type);

        // A CSV that yields no columns is a CSV we could not read. Saying so beats drawing an
        // empty mapping table and leaving somebody to guess.
        if ($preview['headers'] === []) {
            return back()->withErrors([
                'file' => 'That file could not be read. Check it is a CSV with a header row, then try again.',
            ]);
        }

        return back()->with('importPreview', [
            'token'       => $token,
            'entity_type' => $request->entity_type,
            'file_name'   => $request->file('file')->getClientOriginalName(),
            'headers'     => $preview['headers'],
            'sample'      => $preview['sample'],
            'suggested'   => $preview['suggested'],
        ]);
    }

    /**
     * The rows an import did not take, as a CSV.
     *
     * Offered as a file rather than only on screen because the case that most needs it is the one
     * with hundreds of rows in it, and that is precisely the case a panel cannot show. The columns
     * are the ones somebody needs to find the row in their own spreadsheet and decide what to do.
     */
    public function issues(CrmImport $import): StreamedResponse
    {
        $rows = $import->errors ?: [];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row', 'name', 'email', 'company', 'reason']);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['row'] ?? '',
                    $r['name'] ?? '',
                    $r['email'] ?? '',
                    $r['company'] ?? '',
                    $r['reason'] ?? ($r['error'] ?? ''),
                ]);
            }

            fclose($out);
        }, 'skipped-rows-' . $import->uuid . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * What pressing Run Import would do, without doing it.
     *
     * Answered against the mapping actually on screen rather than the suggested one, because the
     * question somebody is asking is "what happens if I press it now" — and the mapping they have
     * adjusted is the only thing that can answer it.
     */
    public function dryRun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'       => 'required|string',
            'entity_type' => 'required|in:lead,contact,account,deal',
            'mapping'     => 'required|array',
            'dedup'       => 'required|in:skip,update,create',
            'product_id'  => 'nullable|exists:crm_products,id',
        ]);

        $path = 'crm-imports/' . $data['token'] . '.csv';
        abort_unless(Storage::exists($path), 422, 'Uploaded file expired — please re-upload.');

        return response()->json($this->service->dryRun(
            $path,
            $data['entity_type'],
            $data['mapping'],
            $data['dedup'],
            $data['product_id'] ?? null,
        ));
    }

    public function commit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token'       => 'required|string',
            'entity_type' => 'required|in:lead,contact,account,deal',
            'file_name'   => 'nullable|string',
            'mapping'     => 'required|array',
            'dedup'       => 'required|in:skip,update,create',
            'product_id'  => 'nullable|exists:crm_products,id',
        ]);

        $path = 'crm-imports/' . $data['token'] . '.csv';
        abort_unless(Storage::exists($path), 422, 'Uploaded file expired — please re-upload.');

        $import = $this->service->import(
            $path,
            $data['entity_type'],
            $data['mapping'],
            $data['dedup'],
            $data['file_name'] ?? 'import.csv',
            $data['product_id'] ?? null,
        );

        Storage::delete($path);

        return back()
            ->with('success', "Imported: {$import->created_count} created, {$import->updated_count} updated, {$import->skipped_count} skipped ({$import->duplicate_count} duplicates).")
            // The import's own id travels with the issues so the screen can offer the full list as
            // a download — a 590-row skip is not something to read in a panel.
            ->with('importIssues', [
                'import'  => $import->uuid,
                'skipped' => $import->skipped_count,
                'rows'    => $import->errors ?: [],
            ]);
    }
}
