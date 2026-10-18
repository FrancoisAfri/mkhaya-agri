<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollExecutiveDashboardService;
use Inertia\Inertia;
use Inertia\Response;

class PayrollDashboardController extends Controller
{
    public function __construct(private PayrollExecutiveDashboardService $dashboardService) {}

    public function index(): Response
    {
        $stats = $this->dashboardService->getPayrollDashboardStats();

        $recentRuns = PayrollRun::select([
            'id', 'uuid', 'ref_number', 'name', 'status',
            'period_start', 'period_end', 'total_gross', 'total_deductions',
            'total_net', 'employee_count', 'ai_risk_level',
        ])
            ->latest('period_start')
            ->limit(5)
            ->get();

        $recentAnomalies = PayrollAnomaly::select([
            'id', 'uuid', 'run_id', 'employee_id', 'anomaly_type',
            'description', 'severity', 'status', 'detected_at',
        ])
            ->latest('detected_at')
            ->limit(10)
            ->get();

        $costDashboard      = $this->dashboardService->getPayrollCostDashboard();
        $complianceDashboard = $this->dashboardService->getComplianceDashboard();
        $riskDashboard       = $this->dashboardService->getPayrollRiskDashboard();
        $varianceDashboard   = $this->dashboardService->getPayrollVarianceDashboard();

        return Inertia::render('Modules/HR/Payroll/Dashboard/Index', [
            'stats'       => $stats,
            'recentRuns'  => $recentRuns,
            'recentAnomalies' => $recentAnomalies,
            'costTrend'   => $costDashboard['monthly_trend'],
            'ytdTotal'    => $costDashboard['ytd_total'],
            'compliance'  => $complianceDashboard,
            'risk'        => $riskDashboard,
            'variance'    => $varianceDashboard,
        ]);
    }
}
