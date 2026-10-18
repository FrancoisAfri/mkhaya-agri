<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollNarrativeReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class PayrollNarrativeReportController extends Controller
{
    public function __construct(private PayrollNarrativeReportService $reportService) {}

    public function index(): Response
    {
        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name', 'status', 'period_start', 'period_end')
            ->latest('period_start')->limit(50)->get();

        $selectedUuid = request()->get('run');
        $run  = $selectedUuid
            ? PayrollRun::where('uuid', $selectedUuid)->first()
            : PayrollRun::where('status', 'released')->latest('period_start')->first();

        $report = null;
        if ($run) {
            $report = $this->reportService->generatePayrollNarrativeReport($run);
        }

        return Inertia::render('Modules/HR/Payroll/NarrativeReports/Index', [
            'runs'        => $runs,
            'selectedRun' => $run ? $run->only(['uuid', 'ref_number', 'name', 'period_start', 'period_end', 'status']) : null,
            'report'      => $report,
        ]);
    }

    public function section(PayrollRun $run, string $section): \Illuminate\Http\JsonResponse
    {
        $data = match ($section) {
            'executive'  => $this->reportService->generateExecutivePayrollSummary($run),
            'departments'=> $this->reportService->generateDepartmentPayrollNarrative($run),
            'variance'   => $this->reportService->generateVarianceExplanation($run),
            'risk'       => $this->reportService->generatePayrollRiskReport($run),
            'compliance' => $this->reportService->generatePayrollComplianceSummary($run),
            'leakage'    => $this->reportService->generatePayrollLeakageReport($run),
            default      => ['error' => 'Unknown section'],
        };

        return response()->json($data);
    }

    public function exportPdf(PayrollRun $run): HttpResponse
    {
        $report = $this->reportService->generatePayrollNarrativeReport($run);

        $pdf = Pdf::loadView('pdf.payroll-narrative-report', [
            'report' => $report,
            'run'    => $run,
        ])->setPaper('a4', 'portrait');

        return $pdf->download("payroll-report-{$run->ref_number}.pdf");
    }
}
