<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Services\CrmReportService;
use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reporting & analytics (spec section 6) — dashboards + BI-ready CSV export.
 * Scoped to the viewer's visibility (rep=own, manager=team, exec/admin=all).
 */
class ReportController extends Controller
{
    public function index(Request $request, CrmVisibilityService $visibility): Response
    {
        $service = (new CrmReportService())
            ->withRange($request->input('from'), $request->input('to'))
            ->forOwners($visibility->visibleOwnerIds());

        // Leave-management style: every report key as a tabular dataset the page
        // shows in tabs, each exportable to CSV.
        $reports = [];
        foreach (CrmReportService::reportKeys() as $key => $label) {
            [$headers, $rows] = $service->dataset($key);
            $reports[$key] = ['label' => $label, 'headers' => $headers, 'rows' => $rows];
        }

        return Inertia::render('Modules/CRM/Reports', [
            'summary' => $service->summary(),
            'reports' => $reports,
            'filters' => ['from' => $request->input('from'), 'to' => $request->input('to')],
        ]);
    }

    /**
     * Stream a report as CSV (Power BI / Tableau ready).
     */
    public function export(Request $request, string $type, CrmVisibilityService $visibility): StreamedResponse
    {
        abort_unless(array_key_exists($type, CrmReportService::reportKeys()), 404);

        $service = (new CrmReportService())
            ->withRange($request->input('from'), $request->input('to'))
            ->forOwners($visibility->visibleOwnerIds());
        [$headers, $rows] = $service->dataset($type);

        $filename = "crm-{$type}-" . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
