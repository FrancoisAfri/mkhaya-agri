<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Services\ContractReportService;
use App\Shared\Exports\TabularExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractReportController extends Controller
{
    public function __construct(private readonly ContractReportService $service) {}

    /**
     * Reports landing page: shows every report (headers + capped rows) plus the key list.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Contract::class);

        $keys = $this->service->reportKeys();

        $reports = [];
        foreach (array_keys($keys) as $key) {
            [$headers, $rows] = $this->service->report($key);
            $reports[$key] = [
                'headers' => $headers,
                'rows' => array_slice($rows, 0, 100),
                'total' => count($rows),
                'truncated' => count($rows) > 100,
            ];
        }

        return Inertia::render('Modules/ContractManagement/Reports/Index', [
            'reportKeys' => $keys,
            'reports' => $reports,
        ]);
    }

    /**
     * Download a single report in xlsx | pdf | csv.
     */
    public function export(string $key, string $format): mixed
    {
        $this->authorize('viewAny', Contract::class);

        $keys = $this->service->reportKeys();
        if (! array_key_exists($key, $keys)) {
            abort(404);
        }

        [$headers, $rows] = $this->service->report($key);
        $title = $keys[$key];
        $date = Carbon::now()->format('Y-m-d');
        $base = "contracts-{$key}-{$date}";

        return match ($format) {
            'xlsx', 'excel' => Excel::download(new TabularExport($headers, $rows), "{$base}.xlsx"),
            'pdf' => Pdf::loadView('reports.tabular', [
                'title' => "Contracts — {$title}",
                'headers' => $headers,
                'rows' => $rows,
                'date' => $date,
            ])->download("{$base}.pdf"),
            default => $this->csv($headers, $rows, "{$base}.csv"),
        };
    }

    private function csv(array $headers, array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, (array) $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
