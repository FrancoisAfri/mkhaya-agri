<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Imports\AssetsImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk asset import (CSV / XLSX).
 *
 * Isolated feature surface: an upload UI, a downloadable template and the
 * import handler. Tenant stamping is automatic — web requests are RLS-scoped
 * and every row is registered through RegisterAssetAction.
 */
class AssetImportController extends Controller
{
    /** Heading row shipped in the template and expected on upload. */
    private const HEADINGS = [
        'name', 'category', 'type', 'location', 'make', 'model',
        'serial_number', 'barcode', 'purchase_cost', 'purchase_date',
        'condition', 'description',
    ];

    /** One illustrative data row for the template. */
    private const EXAMPLE_ROW = [
        'Dell Latitude 7440', 'IT Equipment', 'Laptop', 'Head Office', 'Dell', 'Latitude 7440',
        'SN-ABC-12345', 'BC-0001', '18999.00', '2026-01-15',
        'new', '14-inch business laptop',
    ];

    public function create(Request $request): Response
    {
        return Inertia::render('Modules/Assets/Import/Index', [
            // Populated only on the redirect that follows a store(); null on first load.
            'result'  => $request->session()->get('import_summary'),
            'columns' => self::columnGuide(),
        ]);
    }

    /**
     * Stream a static CSV template: exact heading row + one example row.
     */
    public function template(): StreamedResponse
    {
        $filename = 'asset_import_template.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::HEADINGS);
            fputcsv($out, self::EXAMPLE_ROW);
            fclose($out);
        }, $filename, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $import = new AssetsImport($request->user()->id);

        Excel::import($import, $request->file('file'));

        $summary = [
            'imported' => $import->imported,
            'skipped'  => $import->skipped,
            'errors'   => $import->errors,
        ];

        $message = $import->imported > 0
            ? "{$import->imported} asset(s) imported."
                .($import->skipped > 0 ? " {$import->skipped} row(s) skipped." : '')
            : 'No assets were imported.';

        return redirect()
            ->route('assets.import.index')
            ->with('import_summary', $summary)
            ->with($import->imported > 0 ? 'success' : 'error', $message);
    }

    /**
     * Human-readable column reference for the upload screen.
     *
     * @return array<int, array{column: string, required: bool, notes: string}>
     */
    private static function columnGuide(): array
    {
        return [
            ['column' => 'name',          'required' => true,  'notes' => 'Asset name / description.'],
            ['column' => 'category',      'required' => false, 'notes' => 'Matched by name to an existing asset category.'],
            ['column' => 'type',          'required' => false, 'notes' => 'Matched by name to an existing asset type.'],
            ['column' => 'location',      'required' => false, 'notes' => 'Matched by name to an existing location.'],
            ['column' => 'make',          'required' => false, 'notes' => 'Matched by name to an existing make.'],
            ['column' => 'model',         'required' => false, 'notes' => 'Matched by name (within the make, if given).'],
            ['column' => 'serial_number', 'required' => false, 'notes' => 'Manufacturer serial number.'],
            ['column' => 'barcode',       'required' => false, 'notes' => 'Barcode / asset tag value.'],
            ['column' => 'purchase_cost', 'required' => false, 'notes' => 'Numeric, e.g. 18999.00.'],
            ['column' => 'purchase_date', 'required' => false, 'notes' => 'ISO date, e.g. 2026-01-15.'],
            ['column' => 'condition',     'required' => false, 'notes' => 'new / good / fair / poor. Defaults to new.'],
            ['column' => 'description',   'required' => false, 'notes' => 'Free-text notes.'],
        ];
    }
}
