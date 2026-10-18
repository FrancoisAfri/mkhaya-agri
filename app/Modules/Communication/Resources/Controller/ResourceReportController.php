<?php

namespace App\Modules\Communication\Resources\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Communication\Resources\Services\ResourceReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ResourceReportController extends Controller
{
    public function __construct(
        protected ResourceReportService $service
    ) {}

    /*
    |--------------------------------------------------------------------------
    | PAGE
    |--------------------------------------------------------------------------
    */

    public function index(): Response
    {
        return Inertia::render('Modules/Communication/Resources/Resource/Report/index', [
            ...$this->service->getReportsData(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE — refresh rows via fetch()
    |--------------------------------------------------------------------------
    */

    public function generate(Request $request, string $reportKey)
    {
        $data = $this->service->getReportsData();

        $map = [
            'resource' => $data['resourceReport'],
            'branding' => $data['brandingReport'],
        ];

        $rows = $map[$reportKey] ?? [];

        return response()->json([
            'count' => count($rows),
            'rows'  => $rows,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | EXPORT
    |--------------------------------------------------------------------------
    */

    public function export(Request $request, string $reportKey, string $format)
    {
        $data = $this->service->getReportsData();

        $map = [
            'resource' => ['rows' => $data['resourceReport'], 'title' => 'Resource Library Report'],
            'branding' => ['rows' => $data['brandingReport'], 'title' => 'Corporate Branding Report'],
        ];

        $entry  = $map[$reportKey] ?? ['rows' => [], 'title' => 'Report'];
        $rows   = $entry['rows'];
        $title  = $entry['title'];
        $format = strtolower($format);

        return match ($format) {
            'xlsx'  => $this->exportCsv($rows, $title),
            'pdf'   => $this->exportPdf($rows, $title),
            default => abort(400, "Format non supporté : {$format}"),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | PRIVATE
    |--------------------------------------------------------------------------
    */

    private function exportPdf(array $rows, string $title): \Illuminate\Http\Response
    {
        $filename = Str::slug($title) . '.pdf';

        $pdf = Pdf::loadView('reports.resource', compact('rows', 'title'))
            ->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    private function exportCsv(array $rows, string $title): \Illuminate\Http\Response
    {
        $filename = Str::slug($title) . '.csv';
        $headers  = array_keys($rows[0] ?? []);

        $csv = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(
                fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"',
                $row
            )) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
