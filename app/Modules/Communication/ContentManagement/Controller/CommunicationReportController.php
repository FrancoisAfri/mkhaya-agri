<?php
namespace App\Modules\Communication\ContentManagement\Controller;


use App\Http\Controllers\Controller;
use App\Modules\Communication\ContentManagement\Services\CommunicationReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CommunicationReportController extends Controller
{
    public function __construct(
        protected CommunicationReportService $service
    ) {}

    /*
    |--------------------------------------------------------------------------
    | PAGE
    |--------------------------------------------------------------------------
    */

    public function index(): Response
    {
        return Inertia::render('Modules/Communication/ContentManagement/Reports/index', [
            ...$this->service->getReportsData(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE (refresh rows via fetch)
    |--------------------------------------------------------------------------
    */

    public function generate(Request $request, string $reportKey)
    {
        $data = $this->service->getReportsData();
        $map  = [
            'content'    => $data['contentReport'],
            'spotlight'  => $data['spotlightReport'],
            'ceoMessage' => $data['ceoMessageReport'],
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
        $map  = [
            'content'    => ['rows' => $data['contentReport'],    'title' => 'Content Report'],
            'spotlight'  => ['rows' => $data['spotlightReport'],  'title' => 'Spotlight Report'],
            'ceoMessage' => ['rows' => $data['ceoMessageReport'], 'title' => 'CEO Message Report'],
        ];

        $entry  = $map[$reportKey] ?? ['rows' => [], 'title' => 'Report'];
        $rows   = $entry['rows'];
        $title  = $entry['title'];
        $format = strtolower($format);

        if ($format === 'xlsx') {
            return $this->exportExcel($rows, $title);
        }

        return $this->exportPdf($rows, $title);
    }

    private function exportExcel(array $rows, string $title)
    {
        $filename = Str::slug($title) . '.xlsx';

        $headers = array_keys($rows[0] ?? []);
        $csv     = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $row)) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }


private function exportPdf(array $rows, string $title)
{
    $pdf = Pdf::loadView('reports.content', [
        'rows'  => $rows,
        'title' => $title,
    ]);

    return $pdf->download($title . '.pdf');
}
}
