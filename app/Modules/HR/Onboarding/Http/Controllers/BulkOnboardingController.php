<?php

namespace App\Modules\HR\Onboarding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Onboarding\Actions\ImportBulkOnboardingAction;
use App\Modules\HR\Onboarding\Models\OnboardingBulkImport;
use App\Modules\HR\Onboarding\Models\OnboardingTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk onboarding: download a spreadsheet, fill it in, upload it, get plans.
 *
 * The download and the upload share one column list (ImportBulkOnboardingAction::COLUMNS) so the
 * file a user is handed is by construction the file the importer expects. Generating the template
 * from one list and parsing against another is how "your file is invalid" happens to a file the
 * system produced itself.
 */
class BulkOnboardingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/Onboarding/BulkImport', [
            'templates' => OnboardingTemplate::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'catalogue_key', 'industry', 'company_id'])
                ->map(fn (OnboardingTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    // What a user types in the spreadsheet's `template` column. The catalogue key is
                    // stable across renames; the name is what they will reach for first.
                    'reference' => $t->catalogue_key ?: $t->name,
                    'industry' => $t->industry,
                    'shared' => $t->company_id === null,
                ])->values(),
            'columns' => ImportBulkOnboardingAction::COLUMNS,
            'required' => ImportBulkOnboardingAction::REQUIRED,
            'recent' => OnboardingBulkImport::query()
                ->latest()
                ->limit(10)
                ->get(['uuid', 'filename', 'total_rows', 'created_count', 'failed_count', 'row_errors', 'created_at'])
                ->map(fn (OnboardingBulkImport $i) => [
                    'id' => $i->uuid,
                    'filename' => $i->filename,
                    'total' => $i->total_rows,
                    'created' => $i->created_count,
                    'failed' => $i->failed_count,
                    'errors' => $i->row_errors ?? [],
                    'at' => $i->created_at?->toISOString(),
                ]),
        ]);
    }

    /** The blank spreadsheet, with one worked example row. */
    public function template(): StreamedResponse
    {
        $csv = ImportBulkOnboardingAction::templateCsv();

        return response()->streamDownload(
            fn () => print($csv),
            'onboarding-import-template.csv',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                // Excel reads a UTF-8 CSV as Latin-1 without a BOM, which turns every accented or
                // African-language name into mojibake the moment the file is opened.
                'Content-Encoding' => 'UTF-8',
            ]
        );
    }

    public function import(Request $request, ImportBulkOnboardingAction $action)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'template_id' => ['nullable', 'integer', 'exists:onboarding_templates,id'],
        ]);

        $rows = $this->readCsv($request->file('file')->getRealPath());

        if ($rows === []) {
            return back()->withErrors(['file' => 'That file has a header but no rows.']);
        }

        $import = $action->execute($rows, $request->user()->id, $validated['template_id'] ?? null);

        $import->update(['filename' => $request->file('file')->getClientOriginalName()]);

        // Counts rather than a sentence: the module has no PHP translation catalogue, so __() here
        // would hand the raw key to the user. The page owns the wording and translates it with the
        // rest of its strings.
        return back()->with('bulk_result', [
            'created' => $import->created_count,
            'failed' => $import->failed_count,
            'total' => $import->total_rows,
            'errors' => $import->row_errors ?? [],
        ]);
    }

    /**
     * Undo an import.
     *
     * People who have since been worked on are left alone and reported — see
     * ReverseBulkImportAction. Deleting them would remove somebody's work along with the mistake.
     */
    public function reverse(OnboardingBulkImport $import, \App\Modules\HR\Onboarding\Actions\ReverseBulkImportAction $action)
    {
        $result = $action->execute($import);

        return back()->with('bulk_reversal', $result);
    }

    /**
     * Read the upload into rows keyed by the header.
     *
     * Keyed by the file's own header rather than by position, so a user who reorders the columns —
     * or leaves one out — still gets a sensible import instead of a silent shift where everyone's
     * job title lands in the department field.
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        // Excel writes a UTF-8 BOM that otherwise becomes part of the first header name, so the
        // 'first_name' column silently stops matching.
        $first = fgets($handle);
        rewind($handle);

        if ($first !== false && str_starts_with($first, "\xEF\xBB\xBF")) {
            fseek($handle, 3);
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(fn ($h) => Str::of((string) $h)->trim()->lower()->replace(' ', '_')->toString(), $header);

        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            // A trailing newline yields a single null cell; that is not a row anyone typed.
            if ($line === [null] || $line === ['']) {
                continue;
            }

            $row = [];

            foreach ($header as $index => $column) {
                $row[$column] = $line[$index] ?? null;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
