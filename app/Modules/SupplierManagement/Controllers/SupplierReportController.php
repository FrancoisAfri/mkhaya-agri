<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Services\SupplierReportService;
use App\Shared\Exports\TabularExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierReportController extends Controller
{
    public function __construct(private readonly SupplierReportService $reports) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $reportKeys = $this->reports->reportKeys();

        $reports = [];
        foreach (array_keys($reportKeys) as $key) {
            [$headers, $rows] = $this->reports->report($key);
            $reports[$key] = [
                'headers' => $headers,
                'rows'    => array_slice($rows, 0, 100),
            ];
        }

        return Inertia::render('Modules/SupplierManagement/Reports/Index', [
            'reportKeys' => $reportKeys,
            'reports'    => $reports,
        ]);
    }

    public function export(string $key, string $format): StreamedResponse|\Illuminate\Http\Response
    {
        $this->authorize('viewAny', Supplier::class);

        $reportKeys = $this->reports->reportKeys();
        abort_unless(array_key_exists($key, $reportKeys), 404);

        [$headers, $rows] = $this->reports->report($key);
        $title    = 'Suppliers Report — '.$reportKeys[$key];
        $filename = 'suppliers-'.$key.'-'.now()->format('Y-m-d');

        if ($format === 'xlsx') {
            return Excel::download(new TabularExport($headers, $rows), $filename.'.xlsx');
        }

        if ($format === 'pdf') {
            return Pdf::loadView('reports.tabular', [
                'title'   => $title,
                'headers' => $headers,
                'rows'    => $rows,
                'date'    => now()->format('d M Y'),
            ])->setPaper('a4', 'landscape')->download($filename.'.pdf');
        }

        // Fallback: CSV
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, (array) $row);
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }
}
