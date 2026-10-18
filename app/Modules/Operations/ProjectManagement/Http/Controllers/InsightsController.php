<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\PmAnalyticsService;
use App\Modules\Operations\ProjectManagement\Services\PmReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics and BI reports for Project Management (EPIC-070, PM-1250).
 *
 * Two pages with one job between them: Analytics answers "how is the portfolio doing" in charts,
 * Reports hands the underlying rows over as CSV for whoever wants to do their own analysis. The
 * second matters more than it looks — a team that cannot export will rebuild these numbers badly
 * from a database dump, and then there are two versions of the truth.
 *
 * Cost figures are behind `viewCosts`, the same grant the Delivery page uses. A subcontractor with
 * a login can see the programme and has no business seeing the margin, and hiding a chart is not
 * access control — the numbers are kept out of the payload.
 */
class InsightsController extends Controller
{
    public function __construct(
        private readonly PmAnalyticsService $analytics,
        private readonly PmReportService $reports,
    ) {
    }

    /** Charts. */
    public function analytics(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $canSeeCosts = $request->user()->can('viewCosts', Project::class);

        // The redaction lives in the service: one list of money-carrying charts, so this page and
        // the dashboard cannot drift into disagreeing about it.
        $charts = $this->analytics->dashboard(withCosts: $canSeeCosts);

        return Inertia::render('Modules/ProjectManagement/Insights/Analytics', [
            'charts' => $charts,
            'can'    => ['costs' => $canSeeCosts],
        ]);
    }

    /** Tabular BI datasets, one per area of the module. */
    public function reports(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $service = $this->service($request);
        $canSeeCosts = $request->user()->can('viewCosts', Project::class);

        $datasets = [];

        foreach (PmReportService::reportKeys() as $key => $meta) {
            if (! $canSeeCosts && $this->isCostReport($key)) {
                continue;
            }

            [$headers, $rows] = $service->dataset($key);

            $datasets[$key] = [
                'label'   => $meta['label'],
                'area'    => $meta['area'],
                // Where the rows come from, so an empty table reads as "nobody has entered this
                // yet, here is where" rather than as a broken feature.
                'source'  => $meta['source'],
                'headers' => $headers,
                'rows'    => $rows,
                // The page says when it is showing a slice rather than the whole thing; a table
                // that silently stops at 500 rows is one somebody will draw conclusions from.
                'truncated' => count($rows) >= 500,
            ];
        }

        return Inertia::render('Modules/ProjectManagement/Insights/Reports', [
            'summary'  => $service->summary(),
            'reports'  => $datasets,
            'projects' => Project::orderBy('code')->get(['uuid', 'code', 'name'])
                ->map(fn (Project $p) => ['id' => $p->uuid, 'label' => "{$p->code} · {$p->name}"])->all(),
            'filters'  => [
                'from'    => $request->query('from'),
                'to'      => $request->query('to'),
                'project' => $request->query('project'),
            ],
            'can' => ['costs' => $canSeeCosts],
        ]);
    }

    /** The same dataset, unbounded, as CSV — Power BI, Excel, a warehouse. */
    public function export(Request $request, string $key): StreamedResponse
    {
        $this->authorize('viewAny', Project::class);

        abort_unless(array_key_exists($key, PmReportService::reportKeys()), 404);

        abort_if(
            $this->isCostReport($key) && $request->user()->cannot('viewCosts', Project::class),
            403,
            'You do not have permission to see cost figures.'
        );

        [$headers, $rows] = $this->service($request)->dataset($key, full: true);

        $format = $request->query('format', 'csv');
        $stem = 'pm-' . str_replace('_', '-', $key) . '-' . now()->format('Y-m-d');

        // Three formats for three genuinely different uses: CSV for a spreadsheet somebody will
        // pivot, XLSX for one they will hand around with the columns already sized, PDF for one
        // that goes in a board pack and must not be edited on the way.
        if ($format === 'xlsx') {
            return $this->xlsx($stem, $key, $headers, $rows);
        }

        if ($format === 'pdf') {
            return $this->pdf($stem, $key, $headers, $rows);
        }

        $filename = $stem . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // A BOM, so Excel opens the file as UTF-8 instead of mangling every name with an
            // accent in it — which on this platform is most of them.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, array_map([$this, 'safeCell'], $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function service(Request $request): PmReportService
    {
        $projectId = $request->query('project')
            ? Project::where('uuid', $request->query('project'))->value('id')
            : null;

        return $this->reports
            ->withRange($request->query('from'), $request->query('to'))
            ->forProject($projectId);
    }

    private function isCostReport(string $key): bool
    {
        // Anything carrying money. A subcontractor with a login may see the programme and has no
        // business seeing the margin, and these are absent from the payload rather than hidden.
        return in_array($key, [
            'labour_cost', 'timesheets', 'cost_entries', 'cost_by_project',
        ], true);
    }

    /**
     * Neutralise a cell that a spreadsheet would execute.
     *
     * A value beginning =, +, - or @ is a formula to Excel, and project names come from users. The
     * prefix is what stops an exported report from running something on the machine that opens it.
     */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * The same rows as a spreadsheet.
     *
     * Written through PhpSpreadsheet directly rather than a Maatwebsite export class: the data is
     * already assembled, and an export class would exist only to hand the same array back.
     */
    private function xlsx(string $stem, string $key, array $headers, array $rows)
    {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(\Illuminate\Support\Str::limit(str_replace('_', ' ', $key), 28, ''));

        $sheet->fromArray([$headers], null, 'A1');
        $sheet->fromArray(array_map(fn ($r) => array_map([$this, 'safeCell'], $r), $rows), null, 'A2');

        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($book) {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        }, $stem . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * The same rows as a document.
     *
     * Landscape, because every one of these reports is wider than it is tall, and a portrait table
     * of eleven columns is a table nobody can read.
     */
    private function pdf(string $stem, string $key, array $headers, array $rows)
    {
        $html = view('project-management.report-pdf', [
            'title'   => PmReportService::reportKeys()[$key]['label'] ?? $key,
            'headers' => $headers,
            'rows'    => array_map(fn ($r) => array_map([$this, 'safeCell'], $r), $rows),
            'printed' => now()->toDayDateTimeString(),
        ])->render();

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->download($stem . '.pdf');
    }
}
